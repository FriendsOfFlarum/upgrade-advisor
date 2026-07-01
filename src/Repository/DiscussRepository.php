<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Repository;

use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * Reads compatibility signals from an extension's official support discussion on
 * discuss.flarum.org.
 *
 * The Flarum community tags extension discussions with version tags
 * ("version-1x", "version-2x") and an "abandoned" tag, maintained by the discuss
 * moderators and/or the extension author. These are treated as authoritative.
 */
class DiscussRepository
{
    protected const HOST = 'discuss.flarum.org';
    protected const API_URL = 'https://discuss.flarum.org/api/discussions/%s?include=tags';

    protected const TAG_1X = 'version-1x';
    protected const TAG_2X = 'version-2x';
    protected const TAG_ABANDONED = 'abandoned';

    /**
     * How long to cache a discussion's tags, in seconds.
     */
    protected const CACHE_TTL = 21600; // 6 hours

    public function __construct(
        protected Client $client,
        protected Cache $cache,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Resolve the compatibility signals for a package's support.forum URL.
     *
     * @return array{abandoned: bool, has1x: bool, has2x: bool}|null
     *                                                               Null when the URL isn't a discuss.flarum.org thread or couldn't be read.
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

        return [
            'abandoned' => in_array(self::TAG_ABANDONED, $slugs, true),
            'has1x'     => in_array(self::TAG_1X, $slugs, true),
            'has2x'     => in_array(self::TAG_2X, $slugs, true),
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
        if (!is_string($url) || $url === '') {
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
        $cacheKey = 'fof-upgrade-advisor.discuss.'.$id;

        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->get(sprintf(self::API_URL, $id), [
                'timeout'         => 10,
                'connect_timeout' => 5,
                'headers'         => ['Accept' => 'application/json'],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            $slugs = [];

            foreach ($body['included'] ?? [] as $included) {
                if (($included['type'] ?? null) === 'tags' && isset($included['attributes']['slug'])) {
                    $slugs[] = (string) $included['attributes']['slug'];
                }
            }
        } catch (\Throwable $e) {
            $this->log->info('[fof/upgrade-advisor] Failed to fetch discuss tags for discussion '.$id.': '.$e->getMessage());

            // Don't cache failures — retry on the next run.
            return null;
        }

        $this->cache->put($cacheKey, $slugs, self::CACHE_TTL);

        return $slugs;
    }
}
