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
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CacheGenerationTest extends TestCase
{
    /**
     * @var Repository
     */
    protected $cache;

    protected function setUp(): void
    {
        $this->cache = new Repository(new ArrayStore());
    }

    protected function generation(): CacheGeneration
    {
        return new CacheGeneration($this->cache);
    }

    #[Test]
    public function keys_are_namespaced_and_stable_until_refreshed()
    {
        $key = $this->generation()->key('packagist.acme/foo');

        $this->assertStringStartsWith('fof-upgrade-advisor.', $key);
        $this->assertStringEndsWith('.packagist.acme/foo', $key);
        $this->assertSame($key, $this->generation()->key('packagist.acme/foo'));
    }

    #[Test]
    public function refreshing_moves_every_key_to_a_new_generation()
    {
        $before = $this->generation()->key('packagist.acme/foo');

        $this->assertTrue($this->generation()->refresh(1000));

        $this->assertNotSame($before, $this->generation()->key('packagist.acme/foo'));
    }

    #[Test]
    public function refreshing_again_within_the_cooldown_is_ignored()
    {
        $generation = $this->generation();
        $generation->refresh(1000);
        $key = $generation->key('x');

        $this->assertFalse($generation->refresh(1000 + CacheGeneration::COOLDOWN - 1));
        $this->assertSame($key, $generation->key('x'));

        $this->assertTrue($generation->refresh(1000 + CacheGeneration::COOLDOWN));
        $this->assertNotSame($key, $generation->key('x'));
    }

    #[Test]
    public function records_when_it_was_last_refreshed()
    {
        $this->assertNull($this->generation()->refreshedAt());

        $this->generation()->refresh(1234);

        $this->assertSame(1234, $this->generation()->refreshedAt());
    }

    #[Test]
    public function reads_timestamps_back_from_stores_that_return_numbers_as_strings()
    {
        // Laravel's Redis store saves numeric values unserialized, so they come back as strings.
        $this->cache->forever('fof-upgrade-advisor.refreshed_at', '1234');

        $this->assertSame(1234, $this->generation()->refreshedAt());
        $this->assertFalse($this->generation()->refresh(1234 + CacheGeneration::COOLDOWN - 1));
    }

    #[Test]
    public function data_is_never_reported_older_than_the_lookup_ttl()
    {
        $now = 100000;

        // Never refreshed: entries can be at most one TTL old.
        $this->assertSame($now - CacheGeneration::TTL, $this->generation()->dataAsOf($now));

        // Refreshed recently: data is as fresh as the refresh.
        $this->generation()->refresh($now - 60);
        $this->assertSame($now - 60, $this->generation()->dataAsOf($now));

        // Refreshed long ago: entries have since expired and refetched on their own.
        $this->assertSame($now + 86400 - CacheGeneration::TTL, $this->generation()->dataAsOf($now + 86400));
    }
}
