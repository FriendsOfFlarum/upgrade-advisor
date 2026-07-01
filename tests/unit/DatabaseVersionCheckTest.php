<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Tests\unit;

use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\Check\Checks\DatabaseVersionCheck;
use Illuminate\Database\ConnectionInterface;
use Mockery;
use PHPUnit\Framework\TestCase;

class DatabaseVersionCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * A DatabaseVersionCheck whose rawVersion() returns the given string,
     * without ever touching a real database connection.
     */
    protected function checkReturning(?string $rawVersion): DatabaseVersionCheck
    {
        $db = Mockery::mock(ConnectionInterface::class);

        return new class($db, $rawVersion) extends DatabaseVersionCheck {
            /** @var string|null */
            private $raw;

            public function __construct(ConnectionInterface $db, ?string $raw)
            {
                parent::__construct($db);
                $this->raw = $raw;
            }

            protected function rawVersion(): ?string
            {
                return $this->raw;
            }
        };
    }

    /**
     * @dataProvider mysqlVersions
     * @test
     */
    public function grades_mysql_versions(string $raw, string $expectedStatus)
    {
        $result = $this->checkReturning($raw)->run();

        $this->assertSame($expectedStatus, $result->status, "MySQL $raw");
    }

    public static function mysqlVersions(): array
    {
        return [
            'below JSON floor'        => ['5.6.51', CheckResult::FAIL],
            'JSON floor exactly'      => ['5.7.8', CheckResult::WARNING],
            'above floor, below rec'  => ['8.0.36', CheckResult::WARNING],
            'recommended exactly'     => ['8.4.0', CheckResult::PASS],
            'above recommended'       => ['9.1.0', CheckResult::PASS],
        ];
    }

    /**
     * @dataProvider mariadbVersions
     * @test
     */
    public function grades_mariadb_versions(string $raw, string $expectedStatus)
    {
        $result = $this->checkReturning($raw)->run();

        $this->assertSame($expectedStatus, $result->status, "MariaDB $raw");
        $this->assertSame('MariaDB', $result->meta['server']);
    }

    public static function mariadbVersions(): array
    {
        return [
            'below JSON floor'       => ['10.1.48-MariaDB', CheckResult::FAIL],
            'JSON floor exactly'     => ['10.2.7-MariaDB', CheckResult::WARNING],
            'above floor, below rec' => ['10.11.6-MariaDB-1:10.11.6+maria~ubu', CheckResult::WARNING],
            'recommended exactly'    => ['11.8.0-MariaDB', CheckResult::PASS],
        ];
    }

    /** @test */
    public function strips_the_mariadb_5_5_5_legacy_prefix()
    {
        // Some MariaDB configs report "5.5.5-10.11.6-MariaDB...". The real
        // version (10.11.6) is above recommended-none, so this must not be read
        // as 5.5.5 (which would fail).
        $result = $this->checkReturning('5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu')->run();

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame('10.11.6', $result->meta['version']);
        $this->assertSame('MariaDB', $result->meta['server']);
    }

    /** @test */
    public function warns_when_the_version_cannot_be_determined()
    {
        $result = $this->checkReturning(null)->run();

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame('unknown', $result->meta['warningType']);
    }

    /** @test */
    public function warns_with_below_recommended_type_when_between_floor_and_recommended()
    {
        $result = $this->checkReturning('8.0.36')->run();

        $this->assertSame('below_recommended', $result->meta['warningType']);
    }

    /** @test */
    public function detects_mysql_when_no_mariadb_marker_is_present()
    {
        $result = $this->checkReturning('8.4.0')->run();

        $this->assertSame('MySQL', $result->meta['server']);
    }
}
