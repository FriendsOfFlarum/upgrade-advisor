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

use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ServiceProvider())
        ->register(UpgradeAdvisorServiceProvider::class),

    (new Extend\Settings())
        ->default('fof-upgrade-advisor.repositories', '[]'),

    (new Extend\Routes('api'))
        ->get('/fof/upgrade-advisor/report', 'fof.upgrade-advisor.report', Api\Controller\ShowReportController::class)
        ->post('/fof/upgrade-advisor/test-repository', 'fof.upgrade-advisor.test-repository', Api\Controller\TestRepositoryController::class),
];
