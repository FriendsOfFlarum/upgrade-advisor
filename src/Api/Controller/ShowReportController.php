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

use Flarum\Http\RequestUtil;
use FoF\UpgradeAdvisor\Api\ReportDocument;
use FoF\UpgradeAdvisor\CacheGeneration;
use FoF\UpgradeAdvisor\Check\CheckRegistry;
use FoF\UpgradeAdvisor\Report;
use FoF\UpgradeAdvisor\Target;
use Illuminate\Contracts\Container\Container;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ShowReportController implements RequestHandlerInterface
{
    public function __construct(protected CheckRegistry $registry, protected CacheGeneration $generation, protected Container $container)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(ReportDocument::from($this->report($this->target())));
    }

    protected function report(?Target $target): Report
    {
        return Report::build($this->registry, $this->generation, time(), $target);
    }

    /**
     * Null while the advisor is dormant.
     */
    protected function target(): ?Target
    {
        return $this->container->make('fof-upgrade-advisor.target');
    }
}
