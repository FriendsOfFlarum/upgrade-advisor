<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
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
    protected const CACHE_TTL = 21600; // 6 hours

    public function __construct(
        protected Client $client,
        protected Cache $cache,
        protected LoggerInterface $log
    ) {
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
                'status'             => 'unknown',
                'compatible_version' => null,
                'latest_version'     => null,
                'error'              => true,
            ];
        }

        $latest = null;
        $compatible = null;

        foreach ($versions as $version) {
            $versionNumber = $version['version'] ?? null;

            if (!is_string($versionNumber) || $this->isDev($versionNumber)) {
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
            'status'             => $compatible !== null ? 'compatible' : 'incompatible',
            'compatible_version' => $compatible,
            'latest_version'     => $latest,
            'error'              => false,
        ];
    }

    /**
     * Fetch and cache the version list for a package from Packagist.
     *
     * @return array<int, array<string, mixed>>|null Null on network/parse failure.
     */
    protected function fetchVersions(string $packageName): ?array
    {
        $cacheKey = 'fof-upgrade-advisor.packagist.'.$packageName;

        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->get(sprintf(self::P2_URL, $packageName), [
                'timeout'         => 10,
                'connect_timeout' => 5,
                'headers'         => ['Accept' => 'application/json'],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            $versions = $body['packages'][$packageName] ?? [];
        } catch (\Throwable $e) {
            $this->log->info('[fof/upgrade-advisor] Failed to fetch Packagist metadata for '.$packageName.': '.$e->getMessage());

            // Don't cache failures — retry on the next run.
            return null;
        }

        $this->cache->put($cacheKey, $versions, self::CACHE_TTL);

        return $versions;
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
        return str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');
    }
}
