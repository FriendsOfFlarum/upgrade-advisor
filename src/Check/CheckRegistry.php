<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Check;

use Illuminate\Contracts\Container\Container;

/**
 * Resolves and runs every registered {@see Check}.
 */
class CheckRegistry
{
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Run all registered checks.
     *
     * @return array<int, array{id: string, category: string, result: CheckResult}>
     */
    public function run(): array
    {
        /** @var class-string<Check>[] $classes */
        $classes = $this->container->make('fof-upgrade-advisor.checks');

        $results = [];

        foreach ($classes as $class) {
            /** @var Check $check */
            $check = $this->container->make($class);

            $results[] = [
                'id'       => $check->id(),
                'category' => $check->category(),
                'result'   => $check->run(),
            ];
        }

        return $results;
    }
}
