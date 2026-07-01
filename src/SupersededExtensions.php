<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor;

/**
 * A curated list of 1.x extensions that should be removed before (or as part of)
 * upgrading to the next Flarum major, because their functionality was absorbed
 * into core or moved to a different package.
 *
 * These take precedence over the Packagist compatibility lookup: even if such a
 * package publishes a 2.0-compatible release, it should still be removed.
 */
class SupersededExtensions
{
    /**
     * Reason: the extension's functionality is now part of Flarum core, so the
     * extension can simply be removed.
     */
    public const INTO_CORE = 'into_core';

    /**
     * Reason: the extension has been replaced by a different package, which
     * should be installed instead.
     */
    public const REPLACED = 'replaced';

    /**
     * Reason: the Upgrade Advisor itself. It only supports the current major
     * version, so it should be removed once the upgrade is complete — there is
     * intentionally no next-major release of it.
     */
    public const SELF = 'self';

    /**
     * Map of composer package name => [reason, replacement?].
     */
    protected const MAP = [
        'fof/upgrade-advisor' => [
            'reason' => self::SELF,
        ],
        'fof/nightmode' => [
            'reason' => self::INTO_CORE,
        ],
        'blomstra/fontawesome' => [
            'reason' => self::INTO_CORE,
        ],
        'blomstra/database-queue' => [
            'reason' => self::INTO_CORE,
        ],
        'flarum-com/database-queue' => [
            'reason' => self::INTO_CORE,
        ],
        'blomstra/realtime' => [
            'reason'      => self::REPLACED,
            'replacement' => 'flarum/realtime',
        ],
    ];

    /**
     * Look up superseded info for a package, or null if it isn't superseded.
     *
     * @return array{reason: string, replacement: string|null}|null
     */
    public static function lookup(string $packageName): ?array
    {
        if (!isset(self::MAP[$packageName])) {
            return null;
        }

        $entry = self::MAP[$packageName];

        return [
            'reason'      => $entry['reason'],
            'replacement' => $entry['replacement'] ?? null,
        ];
    }
}
