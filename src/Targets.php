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
 * Which Flarum release the advisor checks readiness for.
 */
class Targets
{
    /**
     * The next release, or null to leave the advisor dormant.
     *
     * To wake it up, return that release with its requirements, e.g. a minor
     * that only raises PHP:
     *
     *     return new Target('2.2.0', phpMinimum: '8.4.0');
     *
     * or a new major with database floors (minimum / recommended):
     *
     *     return new Target('3.0.0', '8.4.0', ['mysql' => ['8.0.0', '8.4.0'], 'pgsql' => ['13.0']]);
     *
     * Requirements are absolute, not a delta from the previous target. The full
     * checklist is in MAINTAINING.md. The advisor goes quiet by itself once a
     * forum reaches the target.
     */
    public static function next(): ?Target
    {
        return null;
    }

    /**
     * The target to check against, or null when the advisor is dormant: there is
     * no target, or core has already reached it.
     */
    public static function resolve(?Target $next, string $coreVersion): ?Target
    {
        if ($next === null || version_compare($coreVersion, $next->version, '>=')) {
            return null;
        }

        return $next;
    }
}
