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

use FoF\UpgradeAdvisor\CoreConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CoreConstraintTest extends TestCase
{
    #[Test]
    #[DataProvider('constraints')]
    public function decides_whether_a_flarum_core_constraint_targets_2_0(string $constraint, bool $expected)
    {
        $this->assertSame($expected, CoreConstraint::supports($constraint, '2.0.0'));
    }

    public static function constraints(): array
    {
        return [
            // Open-ended and written before 2.0 existed: allows 2.0.0 on paper only.
            'open-ended from a beta' => ['>=0.1.0-beta.12', false],
            'open-ended from 1.0' => ['>=1.0', false],
            'anything' => ['*', false],

            'caret 2' => ['^2.0', true],
            'caret 2 pre-release' => ['^2.0.0-beta.1', true],
            'range with a ceiling covering 2' => ['>=1.0 <3.0', true],
            'either major' => ['^1.0 || ^2.0', true],
            'either major, open 2 branch' => ['^1.0 || >=2.0', true],
            'open-ended from 2.0' => ['>=2.0', true],
            'open-ended from a 2.0 beta' => ['>=2.0.0-beta.1', true],

            'caret 1' => ['^1.0', false],
            'below 2' => ['<2.0', false],
            'unparseable' => ['not a constraint', false],
        ];
    }
}
