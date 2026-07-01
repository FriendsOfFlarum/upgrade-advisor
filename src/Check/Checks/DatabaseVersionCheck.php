<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Check\Checks;

use FoF\UpgradeAdvisor\Check\Check;
use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\Targets;
use Illuminate\Database\ConnectionInterface;

class DatabaseVersionCheck implements Check
{
    /**
     * @var ConnectionInterface
     */
    protected $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
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

        if ($raw === null) {
            return CheckResult::warning(null, ['warningType' => 'unknown']);
        }

        $isMariaDb = stripos($raw, 'mariadb') !== false;
        $version = $this->normaliseVersion($raw, $isMariaDb);
        $server = $isMariaDb ? 'MariaDB' : 'MySQL';
        $required = $isMariaDb ? Targets::MARIADB_MINIMUM : Targets::MYSQL_MINIMUM;
        $recommended = $isMariaDb ? Targets::MARIADB_RECOMMENDED : Targets::MYSQL_RECOMMENDED;

        $current = "$server $version";

        $meta = [
            'server'      => $server,
            'version'     => $version,
            'required'    => $required,
            'recommended' => $recommended,
            'raw'         => $raw,
        ];

        if ($version === null) {
            // We recognised the server but couldn't parse a comparable version.
            return CheckResult::warning($current, $meta + ['warningType' => 'unknown']);
        }

        // Below the hard floor Flarum 2.0 will not run at all.
        if (version_compare($version, $required, '<')) {
            return CheckResult::fail($current, $meta);
        }

        // Above the floor but below the recommended version: runs, but warn to
        // encourage upgrading to a modern, supported release.
        if (version_compare($version, $recommended, '<')) {
            return CheckResult::warning($current, $meta + ['warningType' => 'below_recommended']);
        }

        return CheckResult::pass($current, $meta);
    }

    protected function rawVersion(): ?string
    {
        try {
            $row = $this->db->selectOne('SELECT VERSION() AS version');
        } catch (\Throwable $e) {
            return null;
        }

        $version = is_object($row) ? ($row->version ?? null) : ($row['version'] ?? null);

        return $version !== null ? (string) $version : null;
    }

    /**
     * Extract a dotted numeric version (e.g. "8.0.36" or "11.8.2") from the raw
     * VERSION() string.
     *
     * MariaDB reports itself with a legacy "5.5.5-" compatibility prefix in some
     * configurations (e.g. "5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu"). We strip
     * that prefix before parsing so we read the real MariaDB version.
     */
    protected function normaliseVersion(string $raw, bool $isMariaDb): ?string
    {
        if ($isMariaDb && strncmp($raw, '5.5.5-', 6) === 0) {
            $raw = substr($raw, strlen('5.5.5-'));
        }

        if (preg_match('/(\d+\.\d+\.\d+)/', $raw, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
