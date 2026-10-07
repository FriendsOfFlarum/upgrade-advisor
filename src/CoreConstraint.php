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

use Composer\Semver\Comparator;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\VersionParser;

/**
 * Decides whether an extension's flarum/core requirement means it supports a
 * target core version.
 *
 * Satisfying the version isn't enough: an open-ended constraint like
 * ">=0.1.0-beta.12" or "*" technically allows 2.0.0, but was written before
 * 2.0 existed and says nothing about working with it. So each "||" branch must
 * allow the target AND either have a ceiling (e.g. "^2.0", ">=1.0 <3.0") or
 * start at the target major (e.g. ">=2.0").
 */
class CoreConstraint
{
    public static function supports(string $constraint, string $coreVersion): bool
    {
        $parser = new VersionParser();

        try {
            $parsed = $parser->parseConstraints($constraint);
            $target = $parser->normalize($coreVersion);
        } catch (\Throwable $e) {
            return false;
        }

        // The start of the target major, including its pre-releases (e.g. 2.0.0-beta.1).
        $majorStart = explode('.', $target)[0].'.0.0.0-dev';

        foreach (self::branches($parsed) as $branch) {
            if (! $branch->matches(new Constraint('==', $target))) {
                continue;
            }

            $bounded = ! $branch->getUpperBound()->isPositiveInfinity();
            $startsAtTarget = Comparator::greaterThanOrEqualTo($branch->getLowerBound()->getVersion(), $majorStart);

            if ($bounded || $startsAtTarget) {
                return true;
            }
        }

        return false;
    }

    /**
     * The alternatives of an "||" constraint, or the constraint itself.
     *
     * @return ConstraintInterface[]
     */
    protected static function branches(ConstraintInterface $constraint): array
    {
        if ($constraint instanceof MultiConstraint && $constraint->isDisjunctive()) {
            return $constraint->getConstraints();
        }

        return [$constraint];
    }
}
