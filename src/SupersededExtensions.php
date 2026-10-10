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
 * Extensions that should be removed before (or as part of) upgrading to the
 * target, because their functionality was absorbed into core or moved to a
 * different package.
 *
 * These take precedence over the Packagist compatibility lookup: even if such a
 * package publishes a compatible release, it should still be removed.
 *
 * Forums can declare their own mappings with the
 * {@see \FoF\UpgradeAdvisor\Extend\Superseded} extender; those are merged over
 * the curated map below, so they can also correct a bundled entry.
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
     * Curated map of composer package name => [reason, replacement?] for the
     * current target. Empty while the advisor is dormant.
     *
     * @var array<string, array{reason: string, replacement?: string|null}>
     */
    protected const array MAP = [];

    /**
     * @param array<string, array{reason: string, replacement?: string|null}> $additional
     */
    public function __construct(protected array $additional = [])
    {
    }

    /**
     * Look up superseded info for a package, or null if it isn't superseded.
     *
     * Extender-supplied entries take precedence over the curated map, so a
     * forum can correct or override a bundled mapping.
     *
     * @return array{reason: string, replacement: string|null}|null
     */
    public function get(string $packageName): ?array
    {
        $entry = $this->additional[$packageName] ?? static::MAP[$packageName] ?? null;

        if ($entry === null) {
            return null;
        }

        return [
            'reason' => $entry['reason'],
            'replacement' => $entry['replacement'] ?? null,
        ];
    }

    /**
     * Every known mapping, extender entries merged over the curated ones.
     *
     * @return array<string, array{reason: string, replacement?: string|null}>
     */
    public function all(): array
    {
        return array_merge(static::MAP, $this->additional);
    }
}
