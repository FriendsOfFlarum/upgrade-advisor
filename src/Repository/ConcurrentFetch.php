<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Repository;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;

/**
 * Runs many GETs concurrently. A report with a cold cache needs one request per
 * installed extension per source; one at a time that can outlast PHP's
 * max_execution_time on large forums.
 */
class ConcurrentFetch
{
    /**
     * Simultaneous requests per source: enough to cut a cold report from tens of
     * seconds to a few, without hammering Packagist or discuss.
     */
    public const CONCURRENCY = 10;

    /**
     * @param array<string, string> $urls    Keyed by whatever the caller wants back.
     * @param array<string, mixed>  $options Guzzle request options.
     *
     * @return array<string, string|\Throwable> Response body, or the failure, per key.
     */
    public static function bodies(Client $client, array $urls, array $options): array
    {
        $keys = array_keys($urls);
        $results = [];

        $requests = function () use ($client, $urls, $options) {
            foreach (array_values($urls) as $url) {
                yield function () use ($client, $url, $options) {
                    return $client->getAsync($url, $options);
                };
            }
        };

        (new Pool($client, $requests(), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled' => function (ResponseInterface $response, int $index) use (&$results, $keys) {
                $results[$keys[$index]] = (string) $response->getBody();
            },
            'rejected' => function ($reason, int $index) use (&$results, $keys) {
                $results[$keys[$index]] = $reason instanceof \Throwable ? $reason : new \RuntimeException((string) $reason);
            },
        ]))->promise()->wait();

        return $results;
    }
}
