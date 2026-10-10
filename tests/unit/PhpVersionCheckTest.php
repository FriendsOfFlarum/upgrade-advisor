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
use FoF\UpgradeAdvisor\Check\Checks\PhpVersionCheck;
use FoF\UpgradeAdvisor\Target;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PhpVersionCheckTest extends TestCase
{
    protected function check(string $phpMinimum): PhpVersionCheck
    {
        return new PhpVersionCheck(new Target('3.0.0', $phpMinimum));
    }

    #[Test]
    public function passes_when_php_meets_the_targets_minimum()
    {
        $result = $this->check('8.0.0')->run();

        $this->assertSame(CheckResult::PASS, $result->status);
        $this->assertSame(PHP_VERSION, $result->current);
    }

    #[Test]
    public function fails_below_the_targets_minimum_and_says_what_is_required()
    {
        $result = $this->check('99.0.0')->run();

        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('99.0.0', $result->meta['required']);
    }
}
