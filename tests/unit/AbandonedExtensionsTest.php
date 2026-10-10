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
use FoF\UpgradeAdvisor\CacheGeneration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\Test;

class AbandonedExtensionsTest extends TestCase
{
    /**
     * @var array<int, array{request: Request}>
     */
    protected $history = [];

    /**
     * @var MockHandler
     */
    protected $mock;

    /**
     * @var Repository
     */
    protected $cache;

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
        $this->mock = new MockHandler();
        $this->cache = new Repository(new ArrayStore());
        $this->container = Mockery::mock(Container::class);
        $this->settings = Mockery::mock(SettingsRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    protected function list(bool $coreSync): AbandonedExtensions
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $args = [$this->container, $this->settings, new Client(['handler' => $stack]), $this->cache, new NullLogger(), new CacheGeneration($this->cache)];

        if ($coreSync) {
            return new AbandonedExtensions(...$args);
        }

        // Flarum core before 1.8.16 has no AbandonedExtensionsFetcher.
        return new class(...$args) extends AbandonedExtensions {
            protected function coreSyncAvailable(): bool
            {
                return false;
            }
        };
    }

    protected function upstream(): Response
    {
        return new Response(200, [], json_encode([
            'acme/old' => ['replacement' => 'acme/new'],
            'acme/gone' => ['replacement' => ''],
        ]));
    }

    #[Test]
    public function on_older_core_it_reads_the_upstream_list_itself()
    {
        $this->mock->append($this->upstream());
        $list = $this->list(false);

        $this->assertSame('acme/new', $list->status('acme/old'));
        $this->assertTrue($list->status('acme/gone'), 'listed without a replacement');
        $this->assertNull($list->status('acme/fine'), 'not listed');
        $this->assertCount(1, $this->history);
    }

    #[Test]
    public function on_older_core_the_list_is_cached_between_requests()
    {
        $this->mock->append($this->upstream());

        $this->list(false)->status('acme/old');
        $this->assertSame('acme/new', $this->list(false)->status('acme/old'));

        $this->assertCount(1, $this->history);
    }

    #[Test]
    public function on_older_core_a_failed_fetch_means_nothing_is_listed_and_is_retried_next_time()
    {
        $this->mock->append(
            new ConnectException('timed out', new Request('GET', 'https://raw.githubusercontent.com/')),
            $this->upstream()
        );

        $this->assertNull($this->list(false)->status('acme/old'));
        $this->assertSame('acme/new', $this->list(false)->status('acme/old'));
    }

    #[Test]
    public function on_newer_core_it_uses_cores_synced_copy_without_fetching()
    {
        $this->settings->shouldReceive('get')->with(AbandonedExtensionsFetcher::SETTINGS_KEY)
            ->andReturn(json_encode(['acme/old' => ['replacement' => 'acme/new']]));

        $list = $this->list(true);

        $this->assertSame('acme/new', $list->status('acme/old'));
        $this->assertNull($list->status('acme/fine'));
        $this->assertCount(0, $this->history);
    }

    #[Test]
    public function refreshing_on_newer_core_runs_cores_sync_without_emailing_admins()
    {
        $fetcher = Mockery::mock(AbandonedExtensionsFetcher::class);
        $fetcher->shouldReceive('sync')->once()->with(false, false)->andReturn(['count' => 1, 'new' => []]);
        $this->container->shouldReceive('make')->with(AbandonedExtensionsFetcher::class)->andReturn($fetcher);

        $this->list(true)->refresh();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_failing_core_sync_does_not_break_the_refresh()
    {
        $fetcher = Mockery::mock(AbandonedExtensionsFetcher::class);
        $fetcher->shouldReceive('sync')->andThrow(new \RuntimeException('GitHub unreachable'));
        $this->container->shouldReceive('make')->with(AbandonedExtensionsFetcher::class)->andReturn($fetcher);

        $this->list(true)->refresh();

        $this->addToAssertionCount(1);
    }
}
