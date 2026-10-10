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
use FoF\UpgradeAdvisor\Target;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
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

        return $m->invoke(null, $checks);
    }

    #[Test]
    public function all_passing_is_a_pass()
    {
        $this->assertSame(CheckResult::PASS, $this->worst([CheckResult::PASS, CheckResult::PASS]));
    }

    #[Test]
    public function no_checks_is_a_pass()
    {
        $this->assertSame(CheckResult::PASS, $this->worst([]));
    }

    #[Test]
    public function a_single_warning_makes_the_whole_report_warn()
    {
        $this->assertSame(CheckResult::WARNING, $this->worst([CheckResult::PASS, CheckResult::WARNING, CheckResult::PASS]));
    }

    #[Test]
    public function a_single_fail_makes_the_whole_report_fail()
    {
        $this->assertSame(CheckResult::FAIL, $this->worst([CheckResult::PASS, CheckResult::WARNING, CheckResult::FAIL]));
    }

    #[Test]
    public function fail_outranks_warning()
    {
        $this->assertSame(CheckResult::FAIL, $this->worst([CheckResult::WARNING, CheckResult::FAIL, CheckResult::WARNING]));
    }

    #[Test]
    public function records_when_it_was_checked_and_how_old_the_remote_data_can_be()
    {
        $registry = Mockery::mock(CheckRegistry::class);
        $registry->shouldReceive('run')->andReturn([]);

        $generation = new CacheGeneration(new Repository(new ArrayStore()));
        $generation->refresh(5000);

        $report = Report::build($registry, $generation, 5030, $this->target());

        $this->assertSame(5030, $report->checkedAt);
        $this->assertSame(5000, $report->dataAsOf);

        Mockery::close();
    }

    protected function target(): Target
    {
        return new Target('3.0.0', '8.4.0');
    }

    #[Test]
    public function with_a_target_the_report_names_it()
    {
        $registry = Mockery::mock(CheckRegistry::class);
        $registry->shouldReceive('run')->andReturn([]);

        $report = Report::build($registry, new CacheGeneration(new Repository(new ArrayStore())), 5030, $this->target());

        $this->assertSame('3.0', $report->target);

        Mockery::close();
    }

    #[Test]
    public function without_a_target_nothing_is_checked()
    {
        $registry = Mockery::mock(CheckRegistry::class);
        $registry->shouldNotReceive('run');

        $report = Report::build($registry, new CacheGeneration(new Repository(new ArrayStore())), 5030, null);

        $this->assertSame(Report::LATEST, $report->overall);
        $this->assertNull($report->target);
        $this->assertSame([], $report->checks);

        Mockery::close();
    }
}
