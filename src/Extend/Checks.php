<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use FoF\UpgradeAdvisor\Check\Check;
use Illuminate\Contracts\Container\Container;

/**
 * Registers readiness checks with the Upgrade Advisor.
 *
 * Other extensions can add their own checks:
 *
 *     (new \FoF\UpgradeAdvisor\Extend\Checks())
 *         ->add(MyCustomCheck::class)
 *
 * Each registered class must implement {@see Check} and is resolved from the
 * container, so it may type-hint any services it needs.
 */
class Checks implements ExtenderInterface
{
    /**
     * @var class-string<Check>[]
     */
    private array $checks = [];

    /**
     * @param class-string<Check> $checkClass
     */
    public function add(string $checkClass): self
    {
        $this->checks[] = $checkClass;

        return $this;
    }

    public function extend(Container $container, Extension $extension = null): void
    {
        $container->extend('fof-upgrade-advisor.checks', function (array $existing) {
            return array_merge($existing, $this->checks);
        });
    }
}
