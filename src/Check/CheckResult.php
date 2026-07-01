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

/**
 * The outcome of a single readiness check.
 */
class CheckResult
{
    public const PASS = 'pass';
    public const WARNING = 'warning';
    public const FAIL = 'fail';

    /**
     * @param string      $status  One of the status constants above.
     * @param string|null $current A human-readable description of the current state (e.g. "PHP 8.1.2").
     * @param array       $meta    Arbitrary extra data for the frontend (e.g. per-extension breakdowns).
     */
    public function __construct(
        public string $status,
        public ?string $current = null,
        public array $meta = []
    ) {
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
