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

use FoF\UpgradeAdvisor\SupersededExtensions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SupersededExtensionsTest extends TestCase
{
    /**
     * The 1.x curated entries (nightmode, fontawesome, realtime, ...) all
     * described the move to 2.0. When waking the advisor for a new target,
     * add that target's entries to the map and update this test.
     */
    #[Test]
    public function this_release_ships_no_curated_entries()
    {
        $this->assertSame([], (new SupersededExtensions())->all());
    }

    #[Test]
    public function the_advisor_is_not_superseded()
    {
        $this->assertNull((new SupersededExtensions())->get('fof/upgrade-advisor'));
    }

    #[Test]
    public function extender_mappings_are_looked_up_by_package()
    {
        $superseded = new SupersededExtensions([
            'acme/translate' => [
                'reason' => SupersededExtensions::REPLACED,
                'replacement' => 'acme/translate-next',
            ],
        ]);

        $this->assertSame([
            'reason' => SupersededExtensions::REPLACED,
            'replacement' => 'acme/translate-next',
        ], $superseded->get('acme/translate'));
    }

    #[Test]
    public function an_extender_entry_without_a_replacement_reports_null()
    {
        $superseded = new SupersededExtensions([
            'acme/nightmode' => ['reason' => SupersededExtensions::INTO_CORE],
        ]);

        $this->assertSame([
            'reason' => SupersededExtensions::INTO_CORE,
            'replacement' => null,
        ], $superseded->get('acme/nightmode'));
    }

    #[Test]
    public function unknown_packages_are_null()
    {
        $this->assertNull((new SupersededExtensions())->get('acme/not-listed'));
    }

    #[Test]
    public function all_returns_the_extender_entries()
    {
        $all = (new SupersededExtensions([
            'acme/translate' => [
                'reason' => SupersededExtensions::REPLACED,
                'replacement' => 'acme/translate-next',
            ],
        ]))->all();

        $this->assertSame(['acme/translate'], array_keys($all));
    }
}
