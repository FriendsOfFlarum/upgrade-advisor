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

use FoF\UpgradeAdvisor\CacheGeneration;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * Reads compatibility signals from an extension's official support discussion on
 * discuss.flarum.org.
 *
 * The Flarum community tags extension discussions with a tag per supported major
 * ("version-1x", "version-2x", ...) and an "abandoned" tag, maintained by the discuss
 * moderators and/or the extension author. These are treated as authoritative.
 */
class DiscussRepository
{
    protected const HOST = 'discuss.flarum.org';
    protected const API_URL = 'https://discuss.flarum.org/api/discussions/%s?include=tags';

    protected const VERSION_TAG = '/^version-(\d+)x$/';
    protected const TAG_ABANDONED = 'abandoned';

    /**
     * How long to cache a discussion's tags, in seconds.
     */
    protected const CACHE_TTL = CacheGeneration::TTL;

    /**
     * Tag slugs fetched during this request, by discussion id; null marks a
     * failure, so it isn't retried one at a time after a prefetch.
     *
     * @var array<string, string[]|null>
     */
    protected $fetched = [];

    public function __construct(protected Client $client, protected Cache $cache, protected LoggerInterface $log, protected CacheGeneration $generation)
    {
    }

    /**
     * Fetch the tags of every uncached discussion concurrently, so the lookups
     * that follow are answered from memory. URLs that aren't discuss threads
     * are ignored.
     *
     * @param array<int, string|null> $supportForumUrls
     */
    public function prefetch(array $supportForumUrls): void
    {
        $urls = [];

        foreach ($supportForumUrls as $url) {
            $id = $this->discussionId($url);

            if ($id === null || array_key_exists($id, $this->fetched) || isset($urls[$id])) {
                continue;
            }

            $cached = $this->cache->get($this->cacheKey($id));

            if (is_array($cached)) {
                $this->fetched[$id] = $cached;
                continue;
            }

            $urls[$id] = sprintf(self::API_URL, $id);
        }

        foreach (ConcurrentFetch::bodies($this->client, $urls, $this->requestOptions()) as $id => $body) {
            $id = (string) $id; // numeric array keys come back as ints
            $this->fetched[$id] = $body instanceof \Throwable ? $this->failed($id, $body) : $this->store($id, $body);
        }
    }

    /**
     * Resolve the compatibility signals for a package's support.forum URL.
     *
     * @return array{abandoned: bool, majors: int[]}|null The Flarum majors the thread is tagged as
     *                                                    supporting, ascending. Null when the URL isn't a
     *                                                    discuss.flarum.org thread or couldn't be read.
     */
    public function signals(?string $supportForumUrl): ?array
    {
        $id = $this->discussionId($supportForumUrl);

        if ($id === null) {
            return null;
        }

        $slugs = $this->fetchTagSlugs($id);

        if ($slugs === null) {
            return null;
        }

        $majors = [];

        foreach ($slugs as $slug) {
            if (preg_match(self::VERSION_TAG, $slug, $matches) === 1) {
                $majors[] = (int) $matches[1];
            }
        }

        sort($majors);

        return [
            'abandoned' => in_array(self::TAG_ABANDONED, $slugs, true),
            'majors' => $majors,
        ];
    }

    /**
     * Extract the numeric discussion id from a discuss.flarum.org URL.
     *
     * Handles both bare (".../d/12345") and slugged (".../d/12345-some-slug")
     * forms. Returns null for any non-discuss host or unrecognised URL.
     */
    protected function discussionId(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || strcasecmp($host, self::HOST) !== 0) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        // Match /d/<id> optionally followed by "-slug".
        if (preg_match('#/d/(\d+)#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Fetch and cache the tag slugs for a discussion.
     *
     * @return string[]|null Null on network/parse failure.
     */
    protected function fetchTagSlugs(string $id): ?array
    {
        if (array_key_exists($id, $this->fetched)) {
            return $this->fetched[$id];
        }

        $cached = $this->cache->get($this->cacheKey($id));

        if (is_array($cached)) {
            return $this->fetched[$id] = $cached;
        }

        try {
            $response = $this->client->get(sprintf(self::API_URL, $id), $this->requestOptions());
        } catch (\Throwable $e) {
            return $this->fetched[$id] = $this->failed($id, $e);
        }

        return $this->fetched[$id] = $this->store($id, (string) $response->getBody());
    }

    /**
     * @return string[]
     */
    protected function store(string $id, string $body): array
    {
        $decoded = json_decode($body, true);
        $slugs = [];

        foreach ($decoded['included'] ?? [] as $included) {
            if (($included['type'] ?? null) === 'tags' && isset($included['attributes']['slug'])) {
                $slugs[] = (string) $included['attributes']['slug'];
            }
        }

        $this->cache->put($this->cacheKey($id), $slugs, self::CACHE_TTL);

        return $slugs;
    }

    /**
     * Log a failed fetch. Failures aren't cached, so the next run retries.
     *
     * @return null
     */
    protected function failed(string $id, \Throwable $e)
    {
        $this->log->info('[fof/upgrade-advisor] Failed to fetch discuss tags for discussion '.$id.': '.$e->getMessage());

        return null;
    }

    protected function cacheKey(string $id): string
    {
        return $this->generation->key('discuss.'.$id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestOptions(): array
    {
        return [
            'timeout' => 10,
            'connect_timeout' => 5,
            'headers' => ['Accept' => 'application/json'],
        ];
    }
}
