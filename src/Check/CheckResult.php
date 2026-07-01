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
    /**
     * @var string
     */
    public $status;

    /**
     * @var string|null
     */
    public $current;

    /**
     * @var array
     */
    public $meta;

    public function __construct(string $status, ?string $current = null, array $meta = [])
    {
        $this->status = $status;
        $this->current = $current;
        $this->meta = $meta;
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
