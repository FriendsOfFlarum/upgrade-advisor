<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Check\Checks;

use FoF\UpgradeAdvisor\Check\Check;
use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\Targets;

class PhpVersionCheck implements Check
{
    public function id(): string
    {
        return 'php-version';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): CheckResult
    {
        $current = PHP_VERSION;

        if (version_compare($current, Targets::PHP_MINIMUM, '>=')) {
            return CheckResult::pass($current);
        }

        return CheckResult::fail($current, [
            'required' => Targets::PHP_MINIMUM,
        ]);
    }
}
