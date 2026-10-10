<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Tests\unit;

use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\Check\Checks\DatabaseVersionCheck;
use FoF\UpgradeAdvisor\Target;
use Illuminate\Database\ConnectionInterface;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DatabaseVersionCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * Floors (minimum / recommended) used unless a test passes its own target.
     * SQLite deliberately has no recommended version.
     */
    protected function target(): Target
    {
        return new Target('3.0.0', '8.3.0', [
            'mysql' => ['5.7.8', '8.4.0'],
            'mariadb' => ['10.2.7', '11.8.0'],
            'pgsql' => ['13.0', '16.0'],
            'sqlite' => ['3.35.0'],
        ]);
    }

    /**
     * A DatabaseVersionCheck that sees the given driver and raw server version
     * string, without a real database connection.
     */
    protected function checkReturning(?string $rawVersion, string $driver = 'mysql', ?Target $target = null): DatabaseVersionCheck
    {
        $db = Mockery::mock(ConnectionInterface::class);

        return new class($db, $target ?? $this->target(), $driver, $rawVersion) extends DatabaseVersionCheck {
            public function __construct(ConnectionInterface $db, Target $target, private string $driverName, private ?string $raw)
            {
                parent::__construct($db, $target);
            }

            protected function driver(): ?string
            {
                return $this->driverName;
            }

            protected function rawVersion(): ?string
            {
                return $this->raw;
            }
        };
    }

    #[Test]
    #[DataProvider('versions')]
    public function grades_each_engine_against_its_own_floors(string $driver, string $raw, string $server, string $version, string $expectedStatus)
    {
        $result = $this->checkReturning($raw, $driver)->run();

        $this->assertSame($expectedStatus, $result->status, "$server $raw");
        $this->assertSame($server, $result->meta['server']);
        $this->assertSame($version, $result->meta['version']);
    }

    public static function versions(): array
    {
        return [
            'MySQL below the minimum' => ['mysql', '5.6.51', 'MySQL', '5.6.51', CheckResult::FAIL],
            'MySQL at the minimum' => ['mysql', '5.7.8', 'MySQL', '5.7.8', CheckResult::WARNING],
            'MySQL below recommended, distro suffix' => ['mysql', '8.0.36-0ubuntu0.22.04.1', 'MySQL', '8.0.36', CheckResult::WARNING],
            'MySQL at recommended' => ['mysql', '8.4.0', 'MySQL', '8.4.0', CheckResult::PASS],

            'MariaDB on the mariadb driver' => ['mariadb', '11.4.2-MariaDB-ubu2404', 'MariaDB', '11.4.2', CheckResult::WARNING],
            'MariaDB on the mysql driver' => ['mysql', '11.8.1-MariaDB', 'MariaDB', '11.8.1', CheckResult::PASS],
            'MariaDB with the 5.5.5 legacy prefix' => ['mysql', '5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu', 'MariaDB', '10.11.6', CheckResult::WARNING],
            'MariaDB below the minimum' => ['mariadb', '10.1.48-MariaDB', 'MariaDB', '10.1.48', CheckResult::FAIL],

            'PostgreSQL below the minimum' => ['pgsql', '12.9', 'PostgreSQL', '12.9', CheckResult::FAIL],
            'PostgreSQL below recommended' => ['pgsql', '14.2 (Debian 14.2-1.pgdg110+1)', 'PostgreSQL', '14.2', CheckResult::WARNING],
            'PostgreSQL at recommended' => ['pgsql', '16.4 (Debian 16.4-1.pgdg120+1)', 'PostgreSQL', '16.4', CheckResult::PASS],

            'SQLite below the minimum' => ['sqlite', '3.31.1', 'SQLite', '3.31.1', CheckResult::FAIL],
            'SQLite with no recommended version passes at the minimum' => ['sqlite', '3.35.0', 'SQLite', '3.35.0', CheckResult::PASS],
        ];
    }

    #[Test]
    public function the_floors_and_their_label_come_from_the_target()
    {
        $result = $this->checkReturning('14.2', 'pgsql', new Target('3.0.0', '8.3.0', ['pgsql' => ['15.0', '16.0']]))->run();

        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('PostgreSQL 14.2', $result->current);
        $this->assertSame('15.0', $result->meta['required']);
        $this->assertSame('3.0', $result->meta['target']);
    }

    #[Test]
    public function an_engine_the_target_sets_no_floors_for_just_reports_the_server()
    {
        // e.g. a minor that only raises the PHP minimum, or only the MySQL floors.
        $result = $this->checkReturning('9.6.24', 'pgsql', new Target('2.2.0', '8.4.0', ['mysql' => ['8.0.0', '8.4.0']]))->run();

        $this->assertSame(CheckResult::PASS, $result->status);
        $this->assertSame('PostgreSQL 9.6.24', $result->current);
    }

    #[Test]
    public function warns_when_the_version_cannot_be_determined()
    {
        $result = $this->checkReturning(null)->run();

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame('unknown', $result->meta['warningType']);
    }

    #[Test]
    public function warns_on_a_database_driver_it_does_not_know()
    {
        $result = $this->checkReturning('16.00.1000', 'sqlsrv')->run();

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame('unknown', $result->meta['warningType']);
    }

    #[Test]
    public function warns_with_below_recommended_type_when_between_floor_and_recommended()
    {
        $result = $this->checkReturning('8.0.36')->run();

        $this->assertSame('below_recommended', $result->meta['warningType']);
    }
}
