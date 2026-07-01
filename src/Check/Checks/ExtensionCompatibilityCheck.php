<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Check\Checks;

use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;
use FoF\UpgradeAdvisor\Check\Check;
use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\Repository\ComposerRepository;
use FoF\UpgradeAdvisor\Repository\DiscussRepository;
use FoF\UpgradeAdvisor\Repository\PackagistRepository;
use FoF\UpgradeAdvisor\Repository\RepositoryConfig;
use FoF\UpgradeAdvisor\SupersededExtensions;
use FoF\UpgradeAdvisor\Targets;

class ExtensionCompatibilityCheck implements Check
{
    /**
     * The concrete core version we test extension constraints against.
     *
     * Extensions declare constraints like "^2.0" or ">=2.0 <3.0"; asking whether
     * they are satisfied by the first stable release of the target major is the
     * pragmatic definition of "compatible".
     */
    protected const TARGET_CORE_VERSION = '2.0.0';

    /**
     * @var ExtensionManager
     */
    protected $extensions;

    /**
     * @var PackagistRepository
     */
    protected $packagist;

    /**
     * @var DiscussRepository
     */
    protected $discuss;

    /**
     * @var RepositoryConfig
     */
    protected $repositories;

    /**
     * @var ComposerRepository
     */
    protected $composer;

    public function __construct(
        ExtensionManager $extensions,
        PackagistRepository $packagist,
        DiscussRepository $discuss,
        RepositoryConfig $repositories,
        ComposerRepository $composer
    ) {
        $this->extensions = $extensions;
        $this->packagist = $packagist;
        $this->discuss = $discuss;
        $this->repositories = $repositories;
        $this->composer = $composer;
    }

    public function id(): string
    {
        return 'extension-compatibility';
    }

    public function category(): string
    {
        return 'extensions';
    }

    public function run(): CheckResult
    {
        $extensions = [];
        $actionable = 0; // needs action before upgrading (incompatible / superseded / abandoned)
        $unknown = 0;

        foreach ($this->installedExtensions() as $extension) {
            $packageName = $extension->composerJsonAttribute('name');

            if (!is_string($packageName) || $packageName === '') {
                continue;
            }

            $entry = $this->resolve($extension, $packageName);

            $extensions[] = $entry;

            if ($this->isActionable($entry)) {
                $actionable++;
            } elseif ($entry['status'] === 'unknown') {
                $unknown++;
            }
        }

        $meta = [
            'flarumMajor' => Targets::FLARUM_MAJOR,
            'total'       => count($extensions),
            'actionable'  => $actionable,
            'unknown'     => $unknown,
            'extensions'  => $extensions,
        ];

        $current = "$actionable / ".count($extensions);

        if ($actionable > 0) {
            return CheckResult::fail($current, $meta);
        }

        // Everything resolvable is compatible, but some couldn't be looked up.
        if ($unknown > 0) {
            return CheckResult::warning($current, $meta);
        }

        return CheckResult::pass($current, $meta);
    }

    /**
     * Resolve a single extension's status, applying source precedence:
     *
     *   1. Curated superseded list (into-core / replaced / the advisor itself).
     *   2. Core's abandoned status (from the flarum/abandoned-extensions map and
     *      composer's abandoned field, including any replacement) — authoritative,
     *      wins over everything else.
     *   3. Compatibility from Packagist, then any configured private repos:
     *        - a published COMPATIBLE release always wins (even over a discuss
     *          "abandoned" tag — a real 2.0 release means it isn't a dead end);
     *        - otherwise, a discuss "abandoned" tag is reported next (soft signal,
     *          but the most useful message when there's no compatible release);
     *        - otherwise a concrete INCOMPATIBLE result is reported.
     *   4. Discuss version tags (version-1x / version-2x) as a fallback when
     *      nothing above resolved.
     *   5. Otherwise unknown.
     *
     * @return array<string, mixed>
     */
    protected function resolve(Extension $extension, string $packageName): array
    {
        $entry = [
            'id'                    => $extension->getId(),
            'name'                  => $packageName,
            'title'                 => $extension->getTitle(),
            'installedVersion'      => $extension->getVersion(),
            'reason'                => null,
            'replacement'           => null,
            'replacementCompatible' => null,
            'compatibleVersion'     => null,
            'latestVersion'         => null,
            'source'                => null,
            'contact'               => $this->contact($extension),
        ];

        // 1. Curated superseded list.
        $superseded = SupersededExtensions::lookup($packageName);

        if ($superseded !== null) {
            return array_merge($entry, [
                'status'                => 'superseded',
                'reason'                => $superseded['reason'],
                'replacement'           => $superseded['replacement'],
                'replacementCompatible' => $this->replacementCompatible($superseded['replacement']),
            ]);
        }

        // 2a. Core's abandoned status (authoritative, includes replacement).
        // getAbandoned() returns false, true (no replacement), or the replacement
        // package name as a string.
        $abandoned = $extension->getAbandoned();

        if ($abandoned !== false) {
            // Composer's replacement may carry a version constraint (e.g.
            // "acme/foo:^2.0"); keep only the package name.
            $replacement = is_string($abandoned) && $abandoned !== ''
                ? explode(':', $abandoned, 2)[0]
                : null;

            return array_merge($entry, [
                'status'                => 'abandoned',
                'replacement'           => $replacement,
                'replacementCompatible' => $this->replacementCompatible($replacement),
                'source'                => 'core',
            ]);
        }

        // Discuss signals — used for the (soft) abandoned tag and the version-tag
        // fallback. Fetched once here.
        $signals = $this->discuss->signals($extension->composerJsonAttribute('support.forum'));

        // 3. Compatibility from Packagist, then any configured private repos.
        // A concrete result here (compatible OR incompatible) is authoritative
        // over the discuss abandoned tag: if a real 2.0 release has been
        // published, the extension is NOT a dead end, even if a moderator tagged
        // its support thread "abandoned" before the release shipped.
        $compat = $this->packagist->compatibility($packageName, self::TARGET_CORE_VERSION);
        $source = 'packagist';

        if ($compat['status'] === 'unknown') {
            foreach ($this->repositories->all() as $repo) {
                $repoCompat = $this->composer->compatibility($repo, $packageName, self::TARGET_CORE_VERSION);

                if ($repoCompat['status'] !== 'unknown') {
                    $compat = $repoCompat;
                    $source = $repo['type'] === RepositoryConfig::TYPE_FLOXUM ? 'floxum' : 'composer';
                    break;
                }
            }
        }

        // A published compatible release always wins.
        if ($compat['status'] === 'compatible') {
            return array_merge($entry, [
                'status'            => 'compatible',
                'compatibleVersion' => $compat['compatible_version'],
                'latestVersion'     => $compat['latest_version'],
                'source'            => $source,
            ]);
        }

        // No compatible release. If the support thread is tagged abandoned, that
        // is the most useful message (remove / find an alternative).
        if ($signals !== null && $signals['abandoned']) {
            return array_merge($entry, [
                'status' => 'abandoned',
                'source' => 'discuss',
            ]);
        }

        // Otherwise report the concrete incompatible result if we have one.
        if ($compat['status'] === 'incompatible') {
            return array_merge($entry, [
                'status'        => 'incompatible',
                'latestVersion' => $compat['latest_version'],
                'source'        => $source,
            ]);
        }

        // 4. Discuss version tags as a fallback when nothing else resolved.
        if ($signals !== null && ($signals['has2x'] || $signals['has1x'])) {
            return array_merge($entry, [
                'status' => $signals['has2x'] ? 'compatible' : 'incompatible',
                'source' => 'discuss',
            ]);
        }

        // 5. Couldn't determine from any source.
        return array_merge($entry, [
            'status' => 'unknown',
        ]);
    }

    /**
     * Extract contact points from the extension's composer.json so admins can
     * enquire about upgrade plans: the support forum thread, an issue tracker /
     * source URL, and every listed author's email or homepage.
     *
     * @return array{
     *     forum: ?string,
     *     issues: ?string,
     *     source: ?string,
     *     authors: array<int, array{name: ?string, email: ?string, homepage: ?string}>
     * }
     */
    protected function contact(Extension $extension): array
    {
        $clean = function ($value): ?string {
            return is_string($value) && $value !== '' ? $value : null;
        };

        $rawAuthors = $extension->composerJsonAttribute('authors');
        $authors = [];

        if (is_array($rawAuthors)) {
            foreach ($rawAuthors as $author) {
                if (!is_array($author)) {
                    continue;
                }

                $name = $clean($author['name'] ?? null);
                $email = $clean($author['email'] ?? null);
                $homepage = $clean($author['homepage'] ?? null);

                // Skip authors with no usable detail at all.
                if ($name === null && $email === null && $homepage === null) {
                    continue;
                }

                $authors[] = [
                    'name'     => $name,
                    'email'    => $email,
                    'homepage' => $homepage,
                ];
            }
        }

        return [
            'forum'   => $clean($extension->composerJsonAttribute('support.forum')),
            'issues'  => $clean($extension->composerJsonAttribute('support.issues')),
            'source'  => $clean($extension->composerJsonAttribute('support.source')),
            'authors' => $authors,
        ];
    }

    /**
     * Determine whether a suggested replacement package has a target-compatible
     * release on Packagist.
     *
     * @return bool|null True/false when Packagist could answer, null when there
     *                   is no replacement or its compatibility is unknown.
     */
    protected function replacementCompatible(?string $replacement): ?bool
    {
        if ($replacement === null || $replacement === '') {
            return null;
        }

        // Composer's replacement field may include a version constraint, e.g.
        // "acme/foo:^2.0"; we only need the package name for the lookup.
        $package = explode(':', $replacement, 2)[0];

        $compat = $this->packagist->compatibility($package, self::TARGET_CORE_VERSION);

        if ($compat['status'] === 'compatible') {
            return true;
        }

        if ($compat['status'] === 'incompatible') {
            return false;
        }

        return null;
    }

    /**
     * Whether a resolved entry requires action before upgrading (and therefore
     * counts toward the overall fail state).
     *
     * @param array<string, mixed> $entry
     */
    protected function isActionable(array $entry): bool
    {
        if ($entry['status'] === 'incompatible' || $entry['status'] === 'abandoned') {
            return true;
        }

        // Superseded extensions are actionable, except the advisor itself, which
        // is only a "remove as the final step" reminder.
        return $entry['status'] === 'superseded' && $entry['reason'] !== SupersededExtensions::SELF;
    }

    /**
     * All installed extensions, whether enabled or disabled — a disabled
     * extension is still installed and can still block or complicate an upgrade.
     *
     * @return iterable<Extension>
     */
    protected function installedExtensions(): iterable
    {
        return $this->extensions->getExtensions();
    }
}
