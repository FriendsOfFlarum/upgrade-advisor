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
use FoF\UpgradeAdvisor\Repository\DiscussRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\Test;

class DiscussPrefetchTest extends TestCase
{
    /**
     * @var array<int, array{request: Request}>
     */
    protected $history = [];

    /**
     * @var MockHandler
     */
    protected $mock;

    protected function repo(): DiscussRepository
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $cache = new Repository(new ArrayStore());

        return new DiscussRepository(new Client(['handler' => $stack]), $cache, new NullLogger(), new CacheGeneration($cache));
    }

    /**
     * @param string[] $slugs
     */
    protected function discussion(array $slugs): Response
    {
        return new Response(200, [], json_encode([
            'included' => array_map(function (string $slug) {
                return ['type' => 'tags', 'attributes' => ['slug' => $slug]];
            }, $slugs),
        ]));
    }

    #[Test]
    public function prefetching_answers_later_lookups_without_further_requests()
    {
        $repo = $this->repo();
        $this->mock->append(
            $this->discussion(['extensions', 'version-2x']),
            new ConnectException('timed out', new Request('GET', 'https://discuss.flarum.org/api/discussions/2'))
        );

        // Non-discuss URLs and nulls are skipped rather than requested.
        $repo->prefetch(['https://discuss.flarum.org/d/1-acme', 'https://discuss.flarum.org/d/2', 'https://github.com/acme/foo', null]);

        $this->assertSame(['abandoned' => false, 'has1x' => false, 'has2x' => true], $repo->signals('https://discuss.flarum.org/d/1-acme'));
        $this->assertNull($repo->signals('https://discuss.flarum.org/d/2'));
        $this->assertCount(2, $this->history);
    }
}
