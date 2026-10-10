<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Api\Controller;

use FoF\UpgradeAdvisor\AbandonedExtensions;
use FoF\UpgradeAdvisor\CacheGeneration;
use FoF\UpgradeAdvisor\Check\CheckRegistry;
use FoF\UpgradeAdvisor\Report;
use FoF\UpgradeAdvisor\Target;
use Illuminate\Contracts\Container\Container;

/**
 * Re-runs the report with fresh remote lookups. Within the cooldown the
 * existing cache is kept, so the response is simply the current report.
 */
class RefreshReportController extends ShowReportController
{
    public function __construct(CheckRegistry $registry, CacheGeneration $generation, Container $container, protected AbandonedExtensions $abandoned)
    {
        parent::__construct($registry, $generation, $container);
    }

    protected function report(?Target $target): Report
    {
        $now = time();

        // Dormant, there is nothing to refetch. Inside the cooldown nothing is
        // refetched either, including the abandoned list.
        if ($target !== null && $this->generation->refresh($now)) {
            $this->abandoned->refresh();
        }

        return Report::build($this->registry, $this->generation, $now, $target);
    }
}
