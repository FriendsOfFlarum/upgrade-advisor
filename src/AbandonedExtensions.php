<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor;

use Flarum\Extension\AbandonedExtensionsFetcher;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * The flarum/abandoned-extensions list, which flags abandoned extensions (and
 * their replacements) that Packagist doesn't know about.
 *
 * Core 1.8.16+ syncs the list weekly into a setting, and the advisor reuses that
 * copy (asking core to re-sync on Refresh). Older cores don't know the list at
 * all, so the advisor fetches it itself.
 */
class AbandonedExtensions
{
    protected const SOURCE_URL = 'https://raw.githubusercontent.com/flarum/abandoned-extensions/main/abandoned.json';

    /**
     * @var Container
     */
    protected $container;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

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

    /**
     * @var CacheGeneration
     */
    protected $generation;

    /**
     * @var array<string, mixed>|null
     */
    protected $map;

    public function __construct(Container $container, SettingsRepositoryInterface $settings, Client $client, Cache $cache, LoggerInterface $log, CacheGeneration $generation)
    {
        $this->container = $container;
        $this->settings = $settings;
        $this->client = $client;
        $this->cache = $cache;
        $this->log = $log;
        $this->generation = $generation;
    }

    /**
     * The package's status on the list, in the same form as
     * {@see \Flarum\Extension\Extension::getAbandoned()}.
     *
     * @return string|true|null The replacement package, true if listed with no
     *                          replacement, or null if not listed.
     */
    public function status(string $packageName)
    {
        $map = $this->map();

        if (! isset($map[$packageName])) {
            return null;
        }

        $replacement = is_array($map[$packageName]) ? ($map[$packageName]['replacement'] ?? null) : null;

        return is_string($replacement) && $replacement !== '' ? $replacement : true;
    }

    /**
     * Bring the list up to date. On older cores there's nothing to do: the
     * advisor's own copy lives in the generation cache, which Refresh has just
     * invalidated.
     */
    public function refresh(): void
    {
        if (! $this->coreSyncAvailable()) {
            return;
        }

        try {
            // No notify: admins get core's weekly email; a Refresh shouldn't send one.
            $this->container->make(AbandonedExtensionsFetcher::class)->sync(false, false);
        } catch (\Throwable $e) {
            $this->log->info('[fof/upgrade-advisor] Core abandoned-extensions sync failed: '.$e->getMessage());
        }

        $this->map = null;
    }

    protected function coreSyncAvailable(): bool
    {
        return class_exists(AbandonedExtensionsFetcher::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function map(): array
    {
        if ($this->map === null) {
            $this->map = $this->coreSyncAvailable() ? AbandonedExtensionsFetcher::getCachedMap($this->settings) : $this->fetch();
        }

        return $this->map;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetch(): array
    {
        $key = $this->generation->key('abandoned');
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->get(self::SOURCE_URL, [
                'allow_redirects' => false,
                'timeout' => 10,
                'connect_timeout' => 5,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $map = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            $this->log->info('[fof/upgrade-advisor] Failed to fetch the abandoned extensions list: '.$e->getMessage());

            // Don't cache failures — retry on the next run.
            return [];
        }

        if (! is_array($map)) {
            return [];
        }

        $this->cache->put($key, $map, CacheGeneration::TTL);

        return $map;
    }
}
