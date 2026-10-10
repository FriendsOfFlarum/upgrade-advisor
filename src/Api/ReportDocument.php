<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */
namespace FoF\UpgradeAdvisor\Api;

use FoF\UpgradeAdvisor\Report;

/**
 * The JSON:API document the admin page pushes into its store as a
 * `fof-upgrade-advisor-reports` model.
 */
class ReportDocument
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Report $report): array
    {
        return [
            'data' => [
                'type' => 'fof-upgrade-advisor-reports',
                'id' => 'report',
                'attributes' => [
                    'overall' => $report->overall,
                    'target' => $report->target,
                    'checkedAt' => gmdate(DATE_ATOM, $report->checkedAt),
                    'dataAsOf' => gmdate(DATE_ATOM, $report->dataAsOf),
                    'checks' => array_map(fn (array $check) => [
                        'id' => $check['id'],
                        'category' => $check['category'],
                        'status' => $check['result']->status,
                        'current' => $check['result']->current,
                        'meta' => $check['result']->meta,
                    ], $report->checks),
                ],
            ],
        ];
    }
}
