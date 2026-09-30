<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Namespaces every remote-lookup cache key under a generation number, so a
 * forced refresh can invalidate all of them at once.
 *
 * Flarum's cache stores can't delete by prefix; bumping the generation makes
 * the old keys unreachable instead, and they expire on their own within TTL.
 */
class CacheGeneration
{
    /**
     * How long remote lookups (Packagist, Composer repos, Discuss) are cached, in seconds.
     */
    public const TTL = 21600; // 6 hours

    /**
     * Minimum seconds between forced refreshes, so a refresh (one request per
     * installed extension) can't be hammered.
     */
    public const COOLDOWN = 60;

    protected const GENERATION_KEY = 'fof-upgrade-advisor.generation';
    protected const REFRESHED_AT_KEY = 'fof-upgrade-advisor.refreshed_at';

    /**
     * @var Cache
     */
    protected $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function key(string $suffix): string
    {
        return 'fof-upgrade-advisor.g'.$this->generation().'.'.$suffix;
    }

    /**
     * Start a new generation. Returns false (and does nothing) within the cooldown.
     */
    public function refresh(int $now): bool
    {
        $last = $this->refreshedAt();

        if ($last !== null && $now - $last < self::COOLDOWN) {
            return false;
        }

        $this->cache->forever(self::GENERATION_KEY, $this->generation() + 1);
        $this->cache->forever(self::REFRESHED_AT_KEY, $now);

        return true;
    }

    public function refreshedAt(): ?int
    {
        $value = $this->cache->get(self::REFRESHED_AT_KEY);

        // Some stores (Redis) hand numbers back as strings.
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The oldest any cached lookup can be: the last refresh, or one TTL ago if
     * that's more recent (older entries have expired and refetched since).
     */
    public function dataAsOf(int $now): int
    {
        return max((int) $this->refreshedAt(), $now - self::TTL);
    }

    protected function generation(): int
    {
        return (int) $this->cache->get(self::GENERATION_KEY, 0);
    }
}
