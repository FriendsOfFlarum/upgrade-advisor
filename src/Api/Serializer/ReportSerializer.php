<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Api\Serializer;

use Flarum\Api\Serializer\AbstractSerializer;
use FoF\UpgradeAdvisor\Report;
use InvalidArgumentException;

class ReportSerializer extends AbstractSerializer
{
    protected $type = 'fof-upgrade-advisor-reports';

    public function getId($report)
    {
        return 'report';
    }

    /**
     * @param Report $report
     *
     * @return array
     */
    protected function getDefaultAttributes($report)
    {
        if (!($report instanceof Report)) {
            throw new InvalidArgumentException(
                get_class($this).' can only serialize instances of '.Report::class
            );
        }

        return [
            'overall'     => $report->overall,
            'flarumMajor' => $report->flarumMajor,
            'checks'      => array_map(function (array $check) {
                return [
                    'id'       => $check['id'],
                    'category' => $check['category'],
                    'status'   => $check['result']->status,
                    'current'  => $check['result']->current,
                    'meta'     => $check['result']->meta,
                ];
            }, $report->checks),
        ];
    }
}
