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

use FoF\UpgradeAdvisor\CacheGeneration;
use FoF\UpgradeAdvisor\CoreConstraint;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * Queries Packagist for the version metadata of installed composer packages and
 * determines whether any published release is compatible with the target Flarum
 * major version.
 *
 * Extiverse is no longer available, so Packagist's p2 metadata endpoint is the
 * source of truth for extension compatibility.
 */
class PackagistRepository
{
    protected const P2_URL = 'https://repo.packagist.org/p2/%s.json';

    /**
     * How long to cache a package's Packagist metadata, in seconds.
     */
    protected const CACHE_TTL = CacheGeneration::TTL;

    /**
     * Versions fetched during this request, by package; null marks a failure,
     * so it isn't retried one at a time after a prefetch.
     *
     * @var array<string, array<int, array<string, mixed>>|null>
     */
    protected $fetched = [];

    public function __construct(protected Client $client, protected Cache $cache, protected LoggerInterface $log, protected CacheGeneration $generation)
    {
    }

    /**
     * Fetch every uncached package concurrently, so the lookups that follow
     * are answered from memory.
     *
     * @param string[] $packageNames
     */
    public function prefetch(array $packageNames): void
    {
        $urls = [];

        foreach (array_unique($packageNames) as $name) {
            if (array_key_exists($name, $this->fetched)) {
                continue;
            }

            $cached = $this->cache->get($this->cacheKey($name));

            if (is_array($cached)) {
                $this->fetched[$name] = $cached;
                continue;
            }

            $urls[$name] = sprintf(self::P2_URL, $name);
        }

        foreach (ConcurrentFetch::bodies($this->client, $urls, $this->requestOptions()) as $name => $body) {
            $this->fetched[$name] = $body instanceof \Throwable ? $this->failed($name, $body) : $this->store($name, $body);
        }
    }

    /**
     * Determine whether $packageName has a published release whose flarum/core
     * requirement is satisfied by $coreVersion (e.g. "2.0.0").
     *
     * @return array{
     *     status: string,
     *     compatible_version: string|null,
     *     latest_version: string|null,
     *     error: bool
     * }
     */
    public function compatibility(string $packageName, string $coreVersion): array
    {
        $versions = $this->fetchVersions($packageName);

        if ($versions === null) {
            return [
                'status' => 'unknown',
                'compatible_version' => null,
                'latest_version' => null,
                'error' => true,
            ];
        }

        $latest = null;
        $compatible = null;

        foreach ($versions as $version) {
            $versionNumber = $version['version'] ?? null;

            if (! is_string($versionNumber) || $this->isDev($versionNumber)) {
                continue;
            }

            // The p2 endpoint lists versions newest-first, so the first stable
            // release we encounter is the latest.
            if ($latest === null) {
                $latest = $versionNumber;
            }

            $constraint = $version['require']['flarum/core'] ?? null;

            if (is_string($constraint) && $this->constraintAllows($constraint, $coreVersion)) {
                $compatible = $versionNumber;
                break;
            }
        }

        return [
            'status' => $compatible !== null ? 'compatible' : 'incompatible',
            'compatible_version' => $compatible,
            'latest_version' => $latest,
            'error' => false,
        ];
    }

    /**
     * Fetch and cache the version list for a package from Packagist.
     *
     * @return array<int, array<string, mixed>>|null Null on network/parse failure.
     */
    protected function fetchVersions(string $packageName): ?array
    {
        if (array_key_exists($packageName, $this->fetched)) {
            return $this->fetched[$packageName];
        }

        $cached = $this->cache->get($this->cacheKey($packageName));

        if (is_array($cached)) {
            return $this->fetched[$packageName] = $cached;
        }

        try {
            $response = $this->client->get(sprintf(self::P2_URL, $packageName), $this->requestOptions());
        } catch (\Throwable $e) {
            return $this->fetched[$packageName] = $this->failed($packageName, $e);
        }

        return $this->fetched[$packageName] = $this->store($packageName, (string) $response->getBody());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function store(string $packageName, string $body): array
    {
        $decoded = json_decode($body, true);
        $versions = $decoded['packages'][$packageName] ?? [];

        $this->cache->put($this->cacheKey($packageName), $versions, self::CACHE_TTL);

        return $versions;
    }

    /**
     * Log a failed fetch. Failures aren't cached, so the next run retries.
     *
     * @return null
     */
    protected function failed(string $packageName, \Throwable $e)
    {
        $this->log->info('[fof/upgrade-advisor] Failed to fetch Packagist metadata for '.$packageName.': '.$e->getMessage());

        return null;
    }

    protected function cacheKey(string $packageName): string
    {
        return $this->generation->key('packagist.'.$packageName);
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestOptions(): array
    {
        return [
            'timeout' => 10,
            'connect_timeout' => 5,
            'headers' => ['Accept' => 'application/json'],
        ];
    }

    protected function constraintAllows(string $constraint, string $coreVersion): bool
    {
        return CoreConstraint::supports($constraint, $coreVersion);
    }

    protected function isDev(string $version): bool
    {
        return strncmp($version, 'dev-', 4) === 0 || substr($version, -4) === '-dev';
    }
}
