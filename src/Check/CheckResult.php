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
 * The outcome of a single readiness check.
 */
class CheckResult
{
    public const PASS = 'pass';
    public const WARNING = 'warning';
    public const FAIL = 'fail';

    public function __construct(public string $status, public ?string $current = null, public array $meta = [])
    {
    }

    public static function pass(?string $current = null, array $meta = []): self
    {
        return new self(self::PASS, $current, $meta);
    }

    public static function warning(?string $current = null, array $meta = []): self
    {
        return new self(self::WARNING, $current, $meta);
    }

    public static function fail(?string $current = null, array $meta = []): self
    {
        return new self(self::FAIL, $current, $meta);
    }
}
