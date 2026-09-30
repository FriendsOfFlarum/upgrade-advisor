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

use FoF\UpgradeAdvisor\Check\CheckRegistry;
use FoF\UpgradeAdvisor\Check\CheckResult;

/**
 * The aggregate result of running every readiness check.
 *
 * This is a synthetic (non-persisted) entity so it can flow through the standard
 * JSON:API serializer/controller pipeline.
 */
class Report
{
    /**
     * @var array<int, array{id: string, category: string, result: CheckResult}>
     */
    public $checks;

    /**
     * @var string
     */
    public $overall;

    /**
     * @var string
     */
    public $flarumMajor;

    /**
     * Unix timestamp the checks ran at.
     *
     * @var int
     */
    public $checkedAt;

    /**
     * Unix timestamp that cached remote lookups are at least as fresh as.
     *
     * @var int
     */
    public $dataAsOf;

    /**
     * @param array<int, array{id: string, category: string, result: CheckResult}> $checks
     */
    public function __construct(array $checks, string $overall, string $flarumMajor, int $checkedAt, int $dataAsOf)
    {
        $this->checks = $checks;
        $this->overall = $overall;
        $this->flarumMajor = $flarumMajor;
        $this->checkedAt = $checkedAt;
        $this->dataAsOf = $dataAsOf;
    }

    public static function build(CheckRegistry $registry, CacheGeneration $generation, int $now): self
    {
        $checks = $registry->run();

        return new self($checks, self::worst($checks), Targets::FLARUM_MAJOR, $now, $generation->dataAsOf($now));
    }

    /**
     * Reduce all check results to a single overall status: fail beats warning
     * beats pass.
     *
     * @param array<int, array{result: CheckResult}> $checks
     */
    protected static function worst(array $checks): string
    {
        $overall = CheckResult::PASS;

        foreach ($checks as $check) {
            $status = $check['result']->status;

            if ($status === CheckResult::FAIL) {
                return CheckResult::FAIL;
            }

            if ($status === CheckResult::WARNING) {
                $overall = CheckResult::WARNING;
            }
        }

        return $overall;
    }
}
