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
use FoF\UpgradeAdvisor\Repository\PackagistRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PackagistPrefetchTest extends TestCase
{
    /**
     * @var array<int, array{request: Request}>
     */
    protected $history = [];

    /**
     * @var MockHandler
     */
    protected $mock;

    protected function repo(): PackagistRepository
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $cache = new Repository(new ArrayStore());

        return new PackagistRepository(new Client(['handler' => $stack]), $cache, new NullLogger(), new CacheGeneration($cache));
    }

    protected function p2(string $name, string $version, string $core): Response
    {
        return new Response(200, [], json_encode([
            'packages' => [$name => [['version' => $version, 'require' => ['flarum/core' => $core]]]],
        ]));
    }

    /**
     * @return string[]
     */
    protected function requestedPaths(): array
    {
        $paths = array_map(function (array $entry) {
            return $entry['request']->getUri()->getPath();
        }, $this->history);

        sort($paths);

        return $paths;
    }

    #[Test]
    public function prefetching_fetches_every_package_once_so_lookups_need_no_further_requests()
    {
        $repo = $this->repo();
        $this->mock->append(
            $this->p2('acme/one', '2.0.0', '^2.0'),
            $this->p2('acme/two', '1.4.0', '^1.8')
        );

        $repo->prefetch(['acme/one', 'acme/two']);

        $this->assertSame('compatible', $repo->compatibility('acme/one', '2.0.0')['status']);
        $this->assertSame('incompatible', $repo->compatibility('acme/two', '2.0.0')['status']);
        $this->assertSame(['/p2/acme/one.json', '/p2/acme/two.json'], $this->requestedPaths());
    }

    #[Test]
    public function a_failed_package_does_not_break_the_others_and_is_not_retried_in_the_same_run()
    {
        $repo = $this->repo();
        $this->mock->append(
            $this->p2('acme/one', '2.0.0', '^2.0'),
            new ConnectException('timed out', new Request('GET', 'https://repo.packagist.org/p2/acme/down.json'))
        );

        $repo->prefetch(['acme/one', 'acme/down']);

        $this->assertSame('compatible', $repo->compatibility('acme/one', '2.0.0')['status']);
        $this->assertSame('unknown', $repo->compatibility('acme/down', '2.0.0')['status']);
        $this->assertCount(2, $this->history, 'the failed package must not be fetched again one at a time');
    }

    #[Test]
    public function already_cached_and_duplicate_packages_are_not_fetched_again()
    {
        $repo = $this->repo();
        $this->mock->append($this->p2('acme/one', '2.0.0', '^2.0'), $this->p2('acme/two', '2.0.0', '^2.0'));

        $repo->prefetch(['acme/one']);
        $repo->prefetch(['acme/one', 'acme/two', 'acme/two']);

        $this->assertSame(['/p2/acme/one.json', '/p2/acme/two.json'], $this->requestedPaths());
    }

    #[Test]
    public function an_open_ended_constraint_from_before_2_0_is_not_compatible()
    {
        $repo = $this->repo();
        $this->mock->append($this->p2('acme/old', '1.1.1', '>=0.1.0-beta.12'));

        $result = $repo->compatibility('acme/old', '2.0.0');

        $this->assertSame('incompatible', $result['status']);
        $this->assertSame('1.1.1', $result['latest_version']);
    }
}
