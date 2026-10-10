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

use Flarum\Extension\AbandonedExtensionsFetcher;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\UpgradeAdvisor\AbandonedExtensions;
use Illuminate\Contracts\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\Test;

class AbandonedExtensionsTest extends TestCase
{
    /**
     * @var Container|Mockery\MockInterface
     */
    protected $container;

    /**
     * @var SettingsRepositoryInterface|Mockery\MockInterface
     */
    protected $settings;

    protected function setUp(): void
    {
        $this->container = Mockery::mock(Container::class);
        $this->settings = Mockery::mock(SettingsRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    protected function list(): AbandonedExtensions
    {
        return new AbandonedExtensions($this->container, $this->settings, new NullLogger());
    }

    #[Test]
    public function it_reads_cores_synced_copy_of_the_list()
    {
        $this->settings->shouldReceive('get')->with(AbandonedExtensionsFetcher::SETTINGS_KEY)
            ->andReturn(json_encode([
                'acme/old' => ['replacement' => 'acme/new'],
                'acme/gone' => ['replacement' => ''],
            ]));

        $list = $this->list();

        $this->assertSame('acme/new', $list->status('acme/old'));
        $this->assertTrue($list->status('acme/gone'), 'listed without a replacement');
        $this->assertNull($list->status('acme/fine'), 'not listed');
    }

    #[Test]
    public function refreshing_runs_cores_sync_without_emailing_admins()
    {
        $fetcher = Mockery::mock(AbandonedExtensionsFetcher::class);
        $fetcher->shouldReceive('sync')->once()->with(false, false)->andReturn(['count' => 1, 'new' => []]);
        $this->container->shouldReceive('make')->with(AbandonedExtensionsFetcher::class)->andReturn($fetcher);

        $this->list()->refresh();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_failing_core_sync_does_not_break_the_refresh()
    {
        $fetcher = Mockery::mock(AbandonedExtensionsFetcher::class);
        $fetcher->shouldReceive('sync')->andThrow(new \RuntimeException('GitHub unreachable'));
        $this->container->shouldReceive('make')->with(AbandonedExtensionsFetcher::class)->andReturn($fetcher);

        $this->list()->refresh();

        $this->addToAssertionCount(1);
    }
}
