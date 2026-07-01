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
    public function flags_the_advisor_itself_with_the_self_reason()
    {
        $result = SupersededExtensions::lookup('fof/upgrade-advisor');

        $this->assertNotNull($result);
        $this->assertSame(SupersededExtensions::SELF, $result['reason']);
        $this->assertNull($result['replacement']);
    }

    /** @test */
    public function lookup_result_always_has_reason_and_replacement_keys()
    {
        $result = SupersededExtensions::lookup('fof/nightmode');

        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('replacement', $result);
    }
}
