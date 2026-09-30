<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use FoF\UpgradeAdvisor\SupersededExtensions;
use Illuminate\Contracts\Container\Container;

/**
 * Adds to the curated list of extensions that should be removed before (or as
 * part of) upgrading to the next Flarum major.
 *
 * The bundled list only covers packages FoF knows about; forums running private
 * or first-party extensions can declare their own migration paths:
 *
 *     (new \FoF\UpgradeAdvisor\Extend\Superseded())
 *         ->replaced('acme/translate', 'acme/translate-next')
 *         ->intoCore('acme/nightmode')
 *
 * Entries registered here take precedence over the bundled map, so a bundled
 * mapping can also be corrected.
 */
class Superseded implements ExtenderInterface
{
    /**
     * @var array<string, array{reason: string, replacement?: string|null}>
     */
    private $mappings = [];

    /**
     * Declare that a package has been replaced by a different one, which should
     * be installed in its place after the upgrade.
     */
    public function replaced(string $packageName, string $replacement): self
    {
        $this->mappings[$packageName] = [
            'reason' => SupersededExtensions::REPLACED,
            'replacement' => $replacement,
        ];

        return $this;
    }

    /**
     * Declare that a package's functionality is now part of core, so it should
     * simply be removed with nothing installed in its place.
     */
    public function intoCore(string $packageName): self
    {
        $this->mappings[$packageName] = [
            'reason' => SupersededExtensions::INTO_CORE,
        ];

        return $this;
    }

    /**
     * Add several mappings at once.
     *
     * Accepts either a package name mapped to its replacement:
     *
     *     ->add(['acme/translate' => 'acme/translate-next'])
     *
     * or to null when the extension should just be removed:
     *
     *     ->add(['acme/nightmode' => null])
     *
     * @param array<string, string|null> $mappings
     */
    public function add(array $mappings): self
    {
        foreach ($mappings as $packageName => $replacement) {
            if ($replacement === null || $replacement === '') {
                $this->intoCore($packageName);
            } else {
                $this->replaced($packageName, $replacement);
            }
        }

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->extend('fof-upgrade-advisor.superseded', function (array $existing) {
            return array_merge($existing, $this->mappings);
        });
    }
}
