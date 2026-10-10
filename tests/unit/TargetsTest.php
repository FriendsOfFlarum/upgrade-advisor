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

use FoF\UpgradeAdvisor\Target;
use FoF\UpgradeAdvisor\Targets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TargetsTest extends TestCase
{
    protected function target(string $version): Target
    {
        return new Target($version, '8.4.0');
    }

    /**
     * Deliberate tripwire: waking the advisor up means setting a target in
     * Targets::next(), and this test should be updated in the same change.
     */
    #[Test]
    public function this_release_has_no_target_so_the_advisor_is_dormant()
    {
        $this->assertNull(Targets::next());
    }

    #[Test]
    public function there_is_nothing_to_check_without_a_target()
    {
        $this->assertNull(Targets::resolve(null, '2.0.0'));
    }

    #[Test]
    #[DataProvider('coreVersions')]
    public function a_target_is_only_active_while_core_is_below_it(string $target, string $core, bool $active)
    {
        $resolved = Targets::resolve($this->target($target), $core);

        $this->assertSame($active, $resolved !== null);
    }

    public static function coreVersions(): array
    {
        return [
            'next major, on the previous one' => ['3.0.0', '2.4.1', true],
            'next minor, on the previous one' => ['2.1.0', '2.0.3', true],
            'exactly on the target' => ['2.1.0', '2.1.0', false],
            'already past the target' => ['2.1.0', '2.2.0', false],
        ];
    }

    #[Test]
    #[DataProvider('labels')]
    public function the_label_is_the_major_and_minor_and_says_whether_it_is_a_new_major(string $version, string $label, int $major, bool $isMajor)
    {
        $target = $this->target($version);

        $this->assertSame($label, $target->label());
        $this->assertSame($major, $target->major());
        $this->assertSame($isMajor, $target->isMajor());
    }

    public static function labels(): array
    {
        return [
            'major' => ['3.0.0', '3.0', 3, true],
            'minor' => ['2.1.0', '2.1', 2, false],
        ];
    }

    #[Test]
    public function a_misspelt_database_engine_is_rejected_rather_than_silently_ignored()
    {
        $this->expectException(\InvalidArgumentException::class);

        new Target('3.0.0', '8.4.0', ['postgres' => ['13.0']]);
    }
}
