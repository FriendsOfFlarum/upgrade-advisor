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

/**
 * Maps a resolved extension entry to the action an admin needs to take.
 *
 * The keys are stable and exported verbatim in the CSV report, so treat them
 * as public API: add new ones, never rename.
 */
class ExtensionAction
{
    /** Ready — nothing to do. */
    public const NONE = 'none';

    /** Built into core: remove before upgrading, no replacement needed. */
    public const REMOVE = 'remove';

    /** Replaced by another package: remove now, install the replacement after upgrading. */
    public const SWAP_AFTER_UPGRADE = 'swap_after_upgrade';

    /** Abandoned, with a replacement that is (or may be) ready. */
    public const SWITCH_REPLACEMENT = 'switch_replacement';

    /** No 2.0 release and no usable replacement: contact the author or go without. */
    public const NO_PATH = 'no_path';

    /** Couldn't be looked up anywhere. */
    public const UNKNOWN = 'unknown';

    /**
     * In the order the UI and CSV present them: blockers, unchecked, to-dos, ready.
     */
    public const ALL = [
        self::NO_PATH,
        self::UNKNOWN,
        self::REMOVE,
        self::SWAP_AFTER_UPGRADE,
        self::SWITCH_REPLACEMENT,
        self::NONE,
    ];

    /**
     * @param array<string, mixed> $entry
     */
    public static function for(array $entry): string
    {
        switch ($entry['status']) {
            case 'superseded':
                return $entry['reason'] === SupersededExtensions::REPLACED ? self::SWAP_AFTER_UPGRADE : self::REMOVE;

            case 'abandoned':
                // A replacement known to be incompatible is no way forward yet.
                return $entry['replacement'] !== null && $entry['replacementCompatible'] !== false
                    ? self::SWITCH_REPLACEMENT
                    : self::NO_PATH;

            case 'incompatible':
                return self::NO_PATH;

            case 'unknown':
                return self::UNKNOWN;

            default:
                return self::NONE;
        }
    }

    /**
     * The translation key suffix (under `fof-upgrade-advisor.admin.hints.`) and
     * parameters describing what to do, shared by the admin UI and the CSV.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{key: string, params: array<string, string>}
     */
    public static function hint(array $entry): array
    {
        $action = self::for($entry);
        $replacement = ['replacement' => (string) $entry['replacement']];

        switch ($action) {
            case self::NONE:
                $version = $entry['compatibleVersion'] ?? null;

                // Discuss version tags say "compatible" without naming a release.
                return $version !== null
                    ? ['key' => 'none', 'params' => ['version' => $version]]
                    : ['key' => 'none_unversioned', 'params' => []];

            case self::SWAP_AFTER_UPGRADE:
                return ['key' => $action, 'params' => $replacement];

            case self::SWITCH_REPLACEMENT:
                return ['key' => $entry['replacementCompatible'] === true ? 'switch_replacement' : 'switch_replacement_unverified', 'params' => $replacement];

            case self::NO_PATH:
                if ($entry['status'] === 'abandoned') {
                    return $entry['replacement'] !== null
                        ? ['key' => 'no_path_replacement_not_ready', 'params' => $replacement]
                        : ['key' => 'no_path_abandoned', 'params' => []];
                }

                $latest = $entry['latestVersion'] ?? null;

                return $latest !== null
                    ? ['key' => 'no_path', 'params' => ['version' => $latest]]
                    : ['key' => 'no_path_unversioned', 'params' => []];

            default:
                return ['key' => $action, 'params' => []];
        }
    }

    /**
     * Whether the action must be dealt with before upgrading.
     */
    public static function isBlocking(string $action): bool
    {
        return in_array($action, [self::REMOVE, self::SWAP_AFTER_UPGRADE, self::SWITCH_REPLACEMENT, self::NO_PATH], true);
    }

    /**
     * The readiness bucket shown in the admin header and the CSV's "Readiness"
     * column: blocked, unknown, to_do or ready.
     */
    public static function readiness(string $action): string
    {
        if ($action === self::NO_PATH) {
            return 'blocked';
        }

        if ($action === self::UNKNOWN) {
            return 'unknown';
        }

        return self::isReady($action) ? 'ready' : 'to_do';
    }

    /**
     * Whether the extension counts towards the readiness percentage.
     */
    public static function isReady(string $action): bool
    {
        return $action === self::NONE;
    }
}
