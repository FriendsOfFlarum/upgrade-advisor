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
use PHPUnit\Framework\TestCase;

class SupersededExtensionsTest extends TestCase
{
    /** @test */
    public function returns_null_for_a_package_not_in_the_map()
    {
        $this->assertNull(SupersededExtensions::lookup('acme/not-listed'));
    }

    /** @test */
    public function flags_into_core_extensions_with_no_replacement()
    {
        foreach (['fof/nightmode', 'blomstra/fontawesome', 'blomstra/database-queue', 'flarum-com/database-queue'] as $package) {
            $result = SupersededExtensions::lookup($package);

            $this->assertNotNull($result, "$package should be in the superseded map");
            $this->assertSame(SupersededExtensions::INTO_CORE, $result['reason'], "$package should be into_core");
            $this->assertNull($result['replacement'], "$package should have no replacement");
        }
    }

    /** @test */
    public function flags_replaced_extensions_with_their_replacement()
    {
        $result = SupersededExtensions::lookup('blomstra/realtime');

        $this->assertNotNull($result);
        $this->assertSame(SupersededExtensions::REPLACED, $result['reason']);
        $this->assertSame('flarum/realtime', $result['replacement']);
    }

    /** @test */
    public function does_not_flag_the_advisor_itself_which_has_a_2_0_release()
    {
        $this->assertNull(SupersededExtensions::lookup('fof/upgrade-advisor'));
    }

    /** @test */
    public function lookup_result_always_has_reason_and_replacement_keys()
    {
        $result = SupersededExtensions::lookup('fof/nightmode');

        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('replacement', $result);
    }

    /** @test */
    public function extender_mappings_are_merged_over_the_curated_map()
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

        // The curated entries are still present.
        $this->assertSame([
            'reason' => SupersededExtensions::REPLACED,
            'replacement' => 'flarum/realtime',
        ], $superseded->get('blomstra/realtime'));
    }

    /** @test */
    public function extender_mappings_take_precedence_over_a_curated_entry()
    {
        $superseded = new SupersededExtensions([
            'blomstra/realtime' => [
                'reason' => SupersededExtensions::REPLACED,
                'replacement' => 'acme/realtime-fork',
            ],
        ]);

        $this->assertSame([
            'reason' => SupersededExtensions::REPLACED,
            'replacement' => 'acme/realtime-fork',
        ], $superseded->get('blomstra/realtime'));
    }

    /** @test */
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

    /** @test */
    public function unknown_packages_are_still_null_on_an_instance()
    {
        $this->assertNull((new SupersededExtensions())->get('acme/not-listed'));
    }

    /** @test */
    public function all_returns_curated_and_extender_entries()
    {
        $all = (new SupersededExtensions([
            'acme/translate' => [
                'reason' => SupersededExtensions::REPLACED,
                'replacement' => 'acme/translate-next',
            ],
        ]))->all();

        $this->assertArrayHasKey('acme/translate', $all);
        $this->assertArrayHasKey('fof/nightmode', $all);
    }
}
