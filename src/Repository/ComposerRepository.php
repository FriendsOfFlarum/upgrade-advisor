<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Repository;

use Composer\Semver\Semver;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * Looks up a package's compatibility against an admin-configured Composer
 * repository (Floxum or a private Packagist / custom composer repo).
 *
 * Two metadata shapes are supported:
 *   - Inline: packages.json contains all package versions under "packages"
 *     (Floxum works this way).
 *   - Lazy (metadata-url): packages.json declares a "metadata-url" template and
 *     per-package version data is fetched from it (private Packagist, like the
 *     public one).
 */
class ComposerRepository
{
    /**
     * How long to cache repository metadata, in seconds.
     */
    protected const CACHE_TTL = 21600; // 6 hours

    /**
     * Maximum size of a metadata document we're willing to decode into memory.
     * Guards against private-Packagist root packages.json files that inline
     * every version of every package (can be 100+ MB). Per-package p2 documents
     * are always well under this.
     */
    protected const MAX_DECODE_BYTES = 8388608; // 8 MB

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Cache
     */
    protected $cache;

    /**
     * @var LoggerInterface
     */
    protected $log;

    public function __construct(Client $client, Cache $cache, LoggerInterface $log)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->log = $log;
    }

    /**
     * Test whether a repository is reachable and the credentials authenticate,
     * without touching the cache.
     *
     * @param array{type: string, url: string, username: ?string, token: ?string} $repo
     *
     * @return array{ok: bool, reason: string} reason is a translation-key suffix:
     *                                          'ok' | 'auth' | 'unreachable' | 'invalid'
     */
    public function test(array $repo): array
    {
        $url = $repo['url'].'packages.json';

        try {
            $response = $this->client->get($url, [
                'timeout' => 15,
                'connect_timeout' => 5,
                'http_errors' => false,
                'stream' => true,
                'headers' => $this->authHeaders($repo) + ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'unreachable'];
        }

        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'reason' => 'auth'];
        }

        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'reason' => 'unreachable'];
        }

        // Read the head of the body. A standard composer repo returns 401/403 on
        // bad auth, so a 200 is already enough and we only need a small peek to
        // confirm the response is JSON. Floxum, however, returns 200 with an
        // "authorized" flag — and places it at the very END of its (~2 MB)
        // document — so for Floxum we must read far enough to reach it.
        $isFloxum = $repo['type'] === RepositoryConfig::TYPE_FLOXUM;
        $cap = $isFloxum ? self::MAX_DECODE_BYTES : 262144; // 8 MB vs 256 KB

        $stream = $response->getBody();
        $head = '';

        while (! $stream->eof() && strlen($head) < $cap) {
            $head .= $stream->read(8192);
        }

        $stream->close();

        if (! $this->looksLikeJsonObject($head)) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        // Floxum returns HTTP 200 with "authorized": false for a bad/expired token.
        if ($isFloxum && preg_match('#"authorized"\s*:\s*true#', $head) !== 1) {
            return ['ok' => false, 'reason' => 'auth'];
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    protected function looksLikeJsonObject(string $head): bool
    {
        return strncmp(ltrim($head), '{', 1) === 0;
    }

    /**
     * Determine whether $packageName has a release compatible with $coreVersion
     * in the given configured repository.
     *
     * @param array{type: string, url: string, username: ?string, token: ?string} $repo
     *
     * @return array{status: string, compatible_version: string|null, latest_version: string|null}
     */
    public function compatibility(array $repo, string $packageName, string $coreVersion): array
    {
        $versions = $this->versionsFor($repo, $packageName);

        if ($versions === null) {
            return $this->result('unknown');
        }

        $latest = null;
        $compatible = null;

        foreach ($versions as $version) {
            $versionNumber = $version['version'] ?? null;

            if (! is_string($versionNumber) || $this->isDev($versionNumber)) {
                continue;
            }

            if ($latest === null) {
                $latest = $versionNumber;
            }

            $constraint = $version['require']['flarum/core'] ?? null;

            if (is_string($constraint) && $this->constraintAllows($constraint, $coreVersion)) {
                $compatible = $versionNumber;
                break;
            }
        }

        if ($compatible === null && $latest === null) {
            // The package isn't offered by this repository at all.
            return $this->result('unknown');
        }

        return $this->result($compatible !== null ? 'compatible' : 'incompatible', $compatible, $latest);
    }

    /**
     * Resolve the list of version metadata arrays for a package in a repo.
     *
     * @param array{type: string, url: string, username: ?string, token: ?string} $repo
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function versionsFor(array $repo, string $packageName): ?array
    {
        // Prefer the lazy per-package metadata document (small). The root
        // packages.json can be hundreds of MB (private Packagist inlines every
        // version of every package), so we never fully decode it — we read its
        // "metadata-url" from the head, and if that isn't found near the start
        // (because a giant "packages" block precedes it) we fall back to the
        // conventional "<repo>/p2/%package%.json" layout.
        $metadataUrl = $this->rootMetadataUrl($repo);

        $versions = $this->lazyVersions($repo, $metadataUrl, $packageName);

        if ($versions !== null && $versions !== []) {
            return $versions;
        }

        // The metadata document didn't yield versions. Try inline packages, but
        // only if the root is small enough to decode safely (e.g. Floxum).
        $root = $this->fetchJsonCapped($repo, $repo['url'].'packages.json', 'root.'.md5($repo['url']));

        if ($root === null) {
            // Couldn't inline-decode (too large) — trust the lazy result, which
            // was either empty (package absent) or a failed fetch (null).
            return $versions;
        }

        if (isset($root['packages'][$packageName]) && is_array($root['packages'][$packageName])) {
            return array_values($root['packages'][$packageName]);
        }

        return $versions ?? (isset($root['packages']) ? [] : null);
    }

    /**
     * Read the "metadata-url" template from a repository's root packages.json
     * without decoding the (potentially huge) whole document.
     *
     * Streams only the head of the response. Falls back to the conventional
     * "p2/%package%.json" layout when the template isn't found near the start
     * (private Packagist places a large inline "packages" block first). Cached.
     *
     * @param array{type: string, url: string, username: ?string, token: ?string} $repo
     */
    protected function rootMetadataUrl(array $repo): string
    {
        $cacheKey = 'fof-upgrade-advisor.composer.metaurl.'.md5($repo['url']);

        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $head = $this->fetchHead($repo, $repo['url'].'packages.json', 262144); // 256 KB

        $metadataUrl = 'p2/%package%.json'; // convention, relative to the repo url

        if ($head !== null && preg_match('#"metadata-url"\s*:\s*"([^"]+)"#', $head, $matches) === 1) {
            $metadataUrl = $matches[1];
        }

        $this->cache->put($cacheKey, $metadataUrl, self::CACHE_TTL);

        return $metadataUrl;
    }

    /**
     * @param array{type: string, url: string, username: ?string, token: ?string} $repo
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function lazyVersions(array $repo, string $metadataUrl, string $packageName): ?array
    {
        // metadata-url may be absolute or relative to the repo url.
        $template = $this->absoluteUrl($repo['url'], $metadataUrl);
        $url = str_replace('%package%', $packageName, $template);

        $body = $this->fetchJsonCapped($repo, $url, 'pkg.'.md5($repo['url'].$packageName));

        if ($body === null) {
            return null;
        }

        $versions = $body['packages'][$packageName] ?? [];

        // Composer v2 p2 documents are usually minified ("minified":"composer/2.0"):
        // each entry after the first is a delta from the previous one. Expand them
        // so every version carries its full "require" (etc.), otherwise a version's
        // flarum/core constraint would be missing on all but the newest release.
        if (($body['minified'] ?? null) === 'composer/2.0') {
            $versions = $this->expandMinified($versions);
        }

        return $versions;
    }

    /**
     * Expand Composer's "composer/2.0" minified version list, where each entry
     * is a delta applied on top of the previous expanded entry. A field value of
     * "__unset" removes that field. Mirrors Composer's MetadataMinifier::expand().
     *
     * @param array<int, array<string, mixed>> $versions
     *
     * @return array<int, array<string, mixed>>
     */
    protected function expandMinified(array $versions): array
    {
        $expanded = [];
        $previous = [];

        foreach ($versions as $delta) {
            if (! is_array($delta)) {
                continue;
            }

            $current = $previous;

            foreach ($delta as $key => $value) {
                if ($value === '__unset') {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }

            $expanded[] = $current;
            $previous = $current;
        }

        return $expanded;
    }

    /**
     * Fetch, size-cap, and cache a JSON document with the repo's auth applied.
     * Refuses to decode bodies larger than self::MAX_DECODE_BYTES to avoid
     * exhausting memory on huge inline repositories.
     *
     * @param array{type: string, url: string, username: ?string, token: ?string} $repo
     *
     * @return array<string, mixed>|null
     */
    protected function fetchJsonCapped(array $repo, string $url, string $cacheKeySuffix): ?array
    {
        $cacheKey = 'fof-upgrade-advisor.composer.'.$cacheKeySuffix;

        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $raw = $this->fetchHead($repo, $url, self::MAX_DECODE_BYTES + 1);

        if ($raw === null) {
            return null;
        }

        if (strlen($raw) > self::MAX_DECODE_BYTES) {
            $this->log->info('[fof/upgrade-advisor] Composer repo document exceeds size cap, skipping: '.$url);

            return null;
        }

        $body = json_decode($raw, true);

        if (! is_array($body)) {
            return null;
        }

        $this->cache->put($cacheKey, $body, self::CACHE_TTL);

        return $body;
    }

    /**
     * Stream a response body and return at most $maxBytes of it as a string,
     * with the repo's auth applied. Returns null on any transport failure.
     *
     * @param array{type: string, url: string, username: ?string, token: ?string} $repo
     */
    protected function fetchHead(array $repo, string $url, int $maxBytes): ?string
    {
        try {
            $response = $this->client->get($url, [
                'timeout' => 15,
                'connect_timeout' => 5,
                'stream' => true,
                'headers' => $this->authHeaders($repo) + ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return null;
            }

            $stream = $response->getBody();
            $buffer = '';

            while (! $stream->eof() && strlen($buffer) < $maxBytes) {
                $buffer .= $stream->read(8192);
            }

            $stream->close();

            return $buffer;
        } catch (\Throwable $e) {
            $this->log->info('[fof/upgrade-advisor] Failed to fetch Composer repo metadata from '.$url.': '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param array{type: string, url: string, username: ?string, token: ?string} $repo
     *
     * @return array<string, string>
     */
    protected function authHeaders(array $repo): array
    {
        // Floxum authenticates with a bearer token.
        if ($repo['type'] === RepositoryConfig::TYPE_FLOXUM) {
            return ['Authorization' => 'Bearer '.$repo['token']];
        }

        // Private Packagist / custom composer repos use HTTP basic auth.
        if ($repo['token'] !== null) {
            $username = $repo['username'] ?? 'token';

            return ['Authorization' => 'Basic '.base64_encode($username.':'.$repo['token'])];
        }

        return [];
    }

    protected function absoluteUrl(string $base, string $candidate): string
    {
        // Already absolute.
        if (preg_match('#^https?://#i', $candidate) === 1) {
            return $candidate;
        }

        // Root-relative (e.g. "/glowingblue/p2/%package%.json"): resolve against
        // the scheme + host of the repo, NOT its full path — otherwise the repo's
        // path segment gets duplicated.
        if (strncmp($candidate, '/', 1) === 0) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($base, PHP_URL_HOST) ?: '';
            $port = parse_url($base, PHP_URL_PORT);

            return $scheme.'://'.$host.($port ? ':'.$port : '').$candidate;
        }

        // Path-relative.
        return rtrim($base, '/').'/'.ltrim($candidate, '/');
    }

    protected function constraintAllows(string $constraint, string $coreVersion): bool
    {
        try {
            return Semver::satisfies($coreVersion, $constraint);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function isDev(string $version): bool
    {
        return strncmp($version, 'dev-', 4) === 0 || substr($version, -4) === '-dev';
    }

    /**
     * @return array{status: string, compatible_version: string|null, latest_version: string|null}
     */
    protected function result(string $status, ?string $compatible = null, ?string $latest = null): array
    {
        return [
            'status' => $status,
            'compatible_version' => $compatible,
            'latest_version' => $latest,
        ];
    }
}
