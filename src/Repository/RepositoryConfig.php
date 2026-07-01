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

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Reads the admin-configured list of additional Composer repositories used to
 * look up private / marketplace extensions that aren't on public Packagist.
 *
 * Stored as a JSON array in the "fof-upgrade-advisor.repositories" setting. Each
 * entry is one of:
 *
 *   { "type": "floxum",  "token": "..." }
 *   { "type": "composer", "url": "https://repo.packagist.com/acme/", "username": "token", "token": "..." }
 */
class RepositoryConfig
{
    public const TYPE_FLOXUM = 'floxum';
    public const TYPE_COMPOSER = 'composer';

    public const FLOXUM_URL = 'https://floxum.com/composer/';

    protected const SETTING = 'fof-upgrade-advisor.repositories';

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Return the configured repositories, normalised.
     *
     * @return array<int, array{type: string, url: string, username: ?string, token: ?string}>
     */
    public function all(): array
    {
        $raw = $this->settings->get(self::SETTING);

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $repos = [];

        foreach ($decoded as $entry) {
            $normalised = self::normalise($entry);

            if ($normalised !== null) {
                $repos[] = $normalised;
            }
        }

        return $repos;
    }

    /**
     * Normalise a single raw repository entry (from the setting or an admin
     * request) into a structured, validated repo array, or null if invalid.
     *
     * @param mixed $entry
     *
     * @return array{type: string, url: string, username: ?string, token: ?string}|null
     */
    public static function normalise($entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $type = $entry['type'] ?? null;
        $token = isset($entry['token']) ? trim((string) $entry['token']) : null;

        if ($type === self::TYPE_FLOXUM) {
            if ($token === null || $token === '') {
                return null;
            }

            return [
                'type'     => self::TYPE_FLOXUM,
                'url'      => self::FLOXUM_URL,
                'username' => null,
                'token'    => $token,
            ];
        }

        if ($type === self::TYPE_COMPOSER) {
            $url = isset($entry['url']) ? trim((string) $entry['url']) : '';

            if ($url === '') {
                return null;
            }

            // Ensure a trailing slash so packages.json resolves consistently.
            $url = rtrim($url, '/').'/';

            $username = isset($entry['username']) ? trim((string) $entry['username']) : null;

            return [
                'type'     => self::TYPE_COMPOSER,
                'url'      => $url,
                'username' => $username !== '' ? $username : null,
                'token'    => $token !== '' ? $token : null,
            ];
        }

        return null;
    }
}
