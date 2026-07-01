<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor;

use Flarum\Foundation\AbstractServiceProvider;
use FoF\UpgradeAdvisor\Check\Checks\DatabaseVersionCheck;
use FoF\UpgradeAdvisor\Check\Checks\ExtensionCompatibilityCheck;
use FoF\UpgradeAdvisor\Check\Checks\PhpVersionCheck;
use GuzzleHttp\Client;

class UpgradeAdvisorServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        // The registry of check classes. The Checks extender appends to this,
        // and third-party extensions can register their own checks the same way.
        $this->container->singleton('fof-upgrade-advisor.checks', function () {
            return [
                PhpVersionCheck::class,
                DatabaseVersionCheck::class,
                ExtensionCompatibilityCheck::class,
            ];
        });

        // Guzzle client used for external lookups (Packagist, discuss.flarum.org).
        // Bound contextually so we don't interfere with any global Client binding.
        $clientFactory = function () {
            return new Client([
                'headers' => [
                    'User-Agent' => 'fof-upgrade-advisor',
                ],
            ]);
        };

        $this->container
            ->when(Repository\PackagistRepository::class)
            ->needs(Client::class)
            ->give($clientFactory);

        $this->container
            ->when(Repository\DiscussRepository::class)
            ->needs(Client::class)
            ->give($clientFactory);

        $this->container
            ->when(Repository\ComposerRepository::class)
            ->needs(Client::class)
            ->give($clientFactory);
    }
}
