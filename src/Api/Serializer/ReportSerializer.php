<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Api\Serializer;

use Carbon\Carbon;
use Flarum\Api\Serializer\AbstractSerializer;
use FoF\UpgradeAdvisor\Report;
use InvalidArgumentException;

/**
 * @TODO: Remove this in favor of one of the API resource classes that were added.
 *      Or extend an existing API Resource to add this to.
 *      Or use a vanilla RequestHandlerInterface controller.
 *      @link https://docs.flarum.org/2.x/extend/api#endpoints
 */
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
        if (! ($report instanceof Report)) {
            throw new InvalidArgumentException(
                get_class($this).' can only serialize instances of '.Report::class
            );
        }

        return [
            'overall' => $report->overall,
            'flarumMajor' => $report->flarumMajor,
            'checkedAt' => $this->formatDate(Carbon::createFromTimestamp($report->checkedAt)),
            'dataAsOf' => $this->formatDate(Carbon::createFromTimestamp($report->dataAsOf)),
            'checks' => array_map(function (array $check) {
                return [
                    'id' => $check['id'],
                    'category' => $check['category'],
                    'status' => $check['result']->status,
                    'current' => $check['result']->current,
                    'meta' => $check['result']->meta,
                ];
            }, $report->checks),
        ];
    }
}
