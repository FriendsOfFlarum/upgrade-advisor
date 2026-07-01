<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Api\Controller;

use Flarum\Http\RequestUtil;
use FoF\UpgradeAdvisor\Repository\ComposerRepository;
use FoF\UpgradeAdvisor\Repository\RepositoryConfig;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TestRepositoryController implements RequestHandlerInterface
{
    public function __construct(
        protected ComposerRepository $composer
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = $request->getParsedBody() ?? [];

        $repo = RepositoryConfig::normalise([
            'type'     => $body['type'] ?? null,
            'url'      => $body['url'] ?? null,
            'username' => $body['username'] ?? null,
            'token'    => $body['token'] ?? null,
        ]);

        if ($repo === null) {
            return new JsonResponse(['ok' => false, 'reason' => 'invalid']);
        }

        return new JsonResponse($this->composer->test($repo));
    }
}
