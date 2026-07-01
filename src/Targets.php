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
 * Requirements for the next Flarum major version (2.0).
 *
 * Centralised here so the thresholds live in one place as they are refined.
 */
class Targets
{
    /**
     * The Flarum major version this advisor is checking readiness for.
     */
    public const FLARUM_MAJOR = '2.0';

    /**
     * Minimum PHP version required by Flarum 2.0.
     */
    public const PHP_MINIMUM = '8.3.0';

    /**
     * Minimum MySQL version for native JSON column support (the `JSON` type was
     * introduced in MySQL 5.7.8). Flarum 2.0 relies on JSON columns, so below
     * this the check fails.
     */
    public const MYSQL_MINIMUM = '5.7.8';

    /**
     * Recommended MySQL version. Between the minimum and this, the check warns
     * to encourage upgrading to a modern, supported release.
     */
    public const MYSQL_RECOMMENDED = '8.4.0';

    /**
     * Minimum MariaDB version for JSON column support (the `JSON` type — an alias
     * for LONGTEXT with JSON_VALID — was introduced in MariaDB 10.2.7). Below
     * this the check fails.
     */
    public const MARIADB_MINIMUM = '10.2.7';

    /**
     * Recommended MariaDB version. Between the minimum and this, the check warns.
     */
    public const MARIADB_RECOMMENDED = '11.8.0';

    /**
     * The composer constraint an extension must satisfy for flarum/core to be
     * considered 2.0-compatible.
     */
    public const CORE_CONSTRAINT = '^2.0';
}
