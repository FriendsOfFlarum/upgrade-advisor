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
 * A Flarum release the advisor checks readiness for, with its requirements.
 */
class Target
{
    /**
     * Database engines the floors can be keyed by, as Flarum's database drivers name them.
     */
    public const DATABASES = ['mysql', 'mariadb', 'pgsql', 'sqlite'];

    /**
     * @param string                                  $version   The first release of the target, e.g. "3.0.0" or "2.1.0". Extension
     *                                                           constraints are tested against it, so it must be a full version.
     * @param array<string, array{0: string, 1?: string}> $databases Per engine (see DATABASES), [minimum, recommended]: below the
     *                                                           minimum the database check fails, below the recommended version it
     *                                                           warns. Leave an engine out when the target sets no requirement for it.
     */
    public function __construct(
        public readonly string $version,
        public readonly string $phpMinimum,
        public readonly array $databases = [],
    ) {
        foreach (array_keys($databases) as $engine) {
            if (! in_array($engine, self::DATABASES, true)) {
                throw new \InvalidArgumentException("Unknown database engine \"$engine\"; expected one of: ".implode(', ', self::DATABASES));
            }
        }
    }

    /**
     * The [minimum, recommended] floors for an engine; either may be null.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function databaseFloors(string $engine): array
    {
        return [$this->databases[$engine][0] ?? null, $this->databases[$engine][1] ?? null];
    }

    /**
     * How the target is named to admins, e.g. "3.0".
     */
    public function label(): string
    {
        return implode('.', array_slice(explode('.', $this->version), 0, 2));
    }

    public function major(): int
    {
        return (int) explode('.', $this->version)[0];
    }

    public function isMajor(): bool
    {
        return version_compare($this->version, $this->major().'.0.0', '==');
    }
}
