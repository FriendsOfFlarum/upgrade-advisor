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
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * The flarum/abandoned-extensions list, which flags abandoned extensions (and
 * their replacements) that Packagist doesn't know about.
 *
 * Core syncs the list weekly into a setting; the advisor reads that copy and
 * asks core to re-sync on Refresh.
 */
class AbandonedExtensions
{
    /**
     * @var array<string, mixed>|null
     */
    protected $map;

    public function __construct(protected Container $container, protected SettingsRepositoryInterface $settings, protected LoggerInterface $log)
    {
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
        $map = $this->map ??= AbandonedExtensionsFetcher::getCachedMap($this->settings);

        if (! isset($map[$packageName])) {
            return null;
        }

        $replacement = is_array($map[$packageName]) ? ($map[$packageName]['replacement'] ?? null) : null;

        return is_string($replacement) && $replacement !== '' ? $replacement : true;
    }

    public function refresh(): void
    {
        try {
            // No notify: admins get core's weekly email; a Refresh shouldn't send one.
            $this->container->make(AbandonedExtensionsFetcher::class)->sync(false, false);
        } catch (\Throwable $e) {
            $this->log->info('[fof/upgrade-advisor] Core abandoned-extensions sync failed: '.$e->getMessage());
        }

        $this->map = null;
    }
}
