<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Check;

/**
 * A single readiness check for the next Flarum major version.
 *
 * Implementations are resolved from the container, so they may type-hint any
 * services they need in their constructor. Register additional checks with the
 * {@see \FoF\UpgradeAdvisor\Extend\Checks} extender.
 */
interface Check
{
    /**
     * A stable, unique identifier for this check (e.g. "php-version").
     *
     * Used as the serialized id and as the translation key suffix, so it should
     * be kebab-case and never change once released.
     */
    public function id(): string;

    /**
     * The category this check belongs to, used to group results in the UI
     * (e.g. "environment", "database", "extensions").
     */
    public function category(): string;

    /**
     * Run the check and return its result.
     */
    public function run(): CheckResult;
}
