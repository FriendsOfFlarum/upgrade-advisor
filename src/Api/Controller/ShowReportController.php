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

/**
 * @TODO: Remove this in favor of one of the API resource classes that were added.
 *      Or extend an existing API Resource to add this to.
 *      Or use a vanilla RequestHandlerInterface controller.
 *      @link https://docs.flarum.org/2.x/extend/api#endpoints
 */
class ShowReportController extends AbstractShowController
{
    /**
     * {@inheritdoc}
     */
    public $serializer = ReportSerializer::class;

    public function __construct(protected CheckRegistry $registry, protected CacheGeneration $generation)
    {
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
