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
use FoF\UpgradeAdvisor\Report;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ReportTest extends TestCase
{
    /**
     * @param string[] $statuses
     */
    protected function worst(array $statuses): string
    {
        $checks = array_map(fn (string $status) => ['result' => new CheckResult($status)], $statuses);

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
}
