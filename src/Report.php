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
     * Overall status when there is no target: the forum is on the latest
     * release the advisor knows about, so there is nothing to check.
     */
    public const LATEST = 'latest';

    /**
     * @param array<int, array{id: string, category: string, result: CheckResult}> $checks
     * @param string|null                                                           $target The target's label, e.g. "3.0".
     */
    public function __construct(public array $checks, public string $overall, public ?string $target, public int $checkedAt, public int $dataAsOf)
    {
    }

    public static function build(CheckRegistry $registry, CacheGeneration $generation, int $now, ?Target $target): self
    {
        if ($target === null) {
            return new self([], self::LATEST, null, $now, $now);
        }

        $checks = $registry->run();

        return new self($checks, self::worst($checks), $target->label(), $now, $generation->dataAsOf($now));
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
