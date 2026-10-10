<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Check\Checks;

use FoF\UpgradeAdvisor\Check\Check;
use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\Target;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use PDO;

class DatabaseVersionCheck implements Check
{
    /**
     * Display names, keyed by engine as in {@see Target::DATABASES}.
     */
    protected const SERVERS = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'pgsql' => 'PostgreSQL',
        'sqlite' => 'SQLite',
    ];

    public function __construct(protected ConnectionInterface $db, protected Target $target)
    {
    }

    public function id(): string
    {
        return 'database-version';
    }

    public function category(): string
    {
        return 'database';
    }

    public function run(): CheckResult
    {
        $raw = $this->rawVersion();
        $engine = $this->engine($this->driver(), $raw);

        if ($raw === null || $engine === null) {
            return CheckResult::warning(null, ['warningType' => 'unknown']);
        }

        $version = $this->normaliseVersion($raw, $engine);
        $server = self::SERVERS[$engine];
        [$required, $recommended] = $this->target->databaseFloors($engine);

        $current = "$server $version";

        $meta = [
            'server' => $server,
            'version' => $version,
            'required' => $required,
            'recommended' => $recommended,
            'target' => $this->target->label(),
            'raw' => $raw,
        ];

        if ($version === null) {
            // We recognised the server but couldn't parse a comparable version.
            return CheckResult::warning($current, $meta + ['warningType' => 'unknown']);
        }

        // Below the hard floor the target will not run at all.
        if ($required !== null && version_compare($version, $required, '<')) {
            return CheckResult::fail($current, $meta);
        }

        // Above the floor but below the recommended version: runs, but warn to
        // encourage upgrading to a modern, supported release.
        if ($recommended !== null && version_compare($version, $recommended, '<')) {
            return CheckResult::warning($current, $meta + ['warningType' => 'below_recommended']);
        }

        return CheckResult::pass($current, $meta);
    }

    /**
     * The engine key, or null for a driver the advisor doesn't know. A MariaDB
     * server can be connected through the "mysql" driver, so that one is told
     * apart by its version string.
     */
    protected function engine(?string $driver, ?string $raw): ?string
    {
        if ($driver === 'mysql' && $raw !== null && stripos($raw, 'mariadb') !== false) {
            return 'mariadb';
        }

        return $driver !== null && isset(self::SERVERS[$driver]) ? $driver : null;
    }

    protected function driver(): ?string
    {
        return $this->db instanceof Connection ? $this->db->getDriverName() : null;
    }

    /**
     * The server's own version string. Read from PDO rather than
     * Connection::getServerVersion(), which strips the "MariaDB" marker that
     * engine() relies on.
     */
    protected function rawVersion(): ?string
    {
        if (! $this->db instanceof Connection) {
            return null;
        }

        try {
            $version = $this->db->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Extract a dotted numeric version (e.g. "8.0.36", "11.8.2", "16.4") from
     * the raw version string.
     *
     * MariaDB reports itself with a legacy "5.5.5-" compatibility prefix in some
     * configurations (e.g. "5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu"). We strip
     * that prefix before parsing so we read the real MariaDB version.
     */
    protected function normaliseVersion(string $raw, string $engine): ?string
    {
        if ($engine === 'mariadb' && strncmp($raw, '5.5.5-', 6) === 0) {
            $raw = substr($raw, strlen('5.5.5-'));
        }

        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $raw, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
