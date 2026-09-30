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

use Flarum\Api\Controller\AbstractShowController;
use Flarum\Http\RequestUtil;
use FoF\UpgradeAdvisor\Api\Serializer\ReportSerializer;
use FoF\UpgradeAdvisor\CacheGeneration;
use FoF\UpgradeAdvisor\Check\CheckRegistry;
use FoF\UpgradeAdvisor\Report;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class ShowReportController extends AbstractShowController
{
    /**
     * {@inheritdoc}
     */
    public $serializer = ReportSerializer::class;

    /**
     * @var CheckRegistry
     */
    protected $registry;

    /**
     * @var CacheGeneration
     */
    protected $generation;

    public function __construct(CheckRegistry $registry, CacheGeneration $generation)
    {
        $this->registry = $registry;
        $this->generation = $generation;
    }

    /**
     * {@inheritdoc}
     */
    protected function data(ServerRequestInterface $request, Document $document)
    {
        RequestUtil::getActor($request)->assertAdmin();

        return Report::build($this->registry, $this->generation, time());
    }
}
