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

use FoF\UpgradeAdvisor\CacheGeneration;
use FoF\UpgradeAdvisor\Check\CheckRegistry;
use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\Report;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ReportTest extends TestCase
{
    /**
     * @param string[] $statuses
     */
    protected function worst(array $statuses): string
    {
        $checks = array_map(function (string $status) {
            return ['result' => new CheckResult($status)];
        }, $statuses);

        $m = new ReflectionMethod(Report::class, 'worst');
        $m->setAccessible(true);

        return $m->invoke(null, $checks);
    }

    /** @test */
    public function all_passing_is_a_pass()
    {
        $this->assertSame(CheckResult::PASS, $this->worst([CheckResult::PASS, CheckResult::PASS]));
    }

    /** @test */
    public function no_checks_is_a_pass()
    {
        $this->assertSame(CheckResult::PASS, $this->worst([]));
    }

    /** @test */
    public function a_single_warning_makes_the_whole_report_warn()
    {
        $this->assertSame(CheckResult::WARNING, $this->worst([CheckResult::PASS, CheckResult::WARNING, CheckResult::PASS]));
    }

    /** @test */
    public function a_single_fail_makes_the_whole_report_fail()
    {
        $this->assertSame(CheckResult::FAIL, $this->worst([CheckResult::PASS, CheckResult::WARNING, CheckResult::FAIL]));
    }

    /** @test */
    public function fail_outranks_warning()
    {
        $this->assertSame(CheckResult::FAIL, $this->worst([CheckResult::WARNING, CheckResult::FAIL, CheckResult::WARNING]));
    }

    /** @test */
    public function records_when_it_was_checked_and_how_old_the_remote_data_can_be()
    {
        $registry = Mockery::mock(CheckRegistry::class);
        $registry->shouldReceive('run')->andReturn([]);

        $generation = new CacheGeneration(new Repository(new ArrayStore()));
        $generation->refresh(5000);

        $report = Report::build($registry, $generation, 5030);

        $this->assertSame(5030, $report->checkedAt);
        $this->assertSame(5000, $report->dataAsOf);

        Mockery::close();
    }
}
