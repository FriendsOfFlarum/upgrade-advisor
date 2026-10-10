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

use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;
use FoF\UpgradeAdvisor\AbandonedExtensions;
use FoF\UpgradeAdvisor\Check\Check;
use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\ExtensionAction;
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

    /**
     * @var SupersededExtensions
     */
    protected $superseded;

    /**
     * @var AbandonedExtensions|null
     */
    protected $abandoned;

    public function __construct(
        ExtensionManager $extensions,
        PackagistRepository $packagist,
        DiscussRepository $discuss,
        RepositoryConfig $repositories,
        ComposerRepository $composer,
        ?SupersededExtensions $superseded = null,
        ?AbandonedExtensions $abandoned = null
    ) {
        $this->extensions = $extensions;
        $this->packagist = $packagist;
        $this->discuss = $discuss;
        $this->repositories = $repositories;
        $this->composer = $composer;
        // Optional so existing callers constructing this directly keep working;
        // the container always supplies the bound instance carrying any
        // extender-registered mappings.
        $this->superseded = $superseded ?? new SupersededExtensions();
        $this->abandoned = $abandoned;
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
        $ready = 0; // nothing to do before upgrading
        $actionable = 0; // needs action before upgrading (incompatible / superseded / abandoned)
        $unknown = 0;
        $blocked = 0; // no 2.0 path: the only thing that truly blocks the upgrade

        $installed = [];

        foreach ($this->installedExtensions() as $extension) {
            $packageName = $extension->composerJsonAttribute('name');

            if (is_string($packageName) && $packageName !== '') {
                $installed[$packageName] = $extension;
            }
        }

        // Fetch all remote data in concurrent batches up front; resolve() then
        // reads it from memory instead of making a request per extension.
        $this->packagist->prefetch(array_keys($installed));
        $this->discuss->prefetch(array_values(array_map(function (Extension $extension) {
            return $extension->composerJsonAttribute('support.forum');
        }, $installed)));

        foreach ($installed as $packageName => $extension) {
            $entry = $this->resolve($extension, $packageName);
            $entry['action'] = ExtensionAction::for($entry);
            $entry['hint'] = ExtensionAction::hint($entry);

            $extensions[] = $entry;

            if ($entry['action'] === ExtensionAction::NO_PATH) {
                $blocked++;
            }

            if (ExtensionAction::isBlocking($entry['action'])) {
                $actionable++;
            } elseif (ExtensionAction::isReady($entry['action'])) {
                $ready++;
            } else {
                $unknown++;
            }
        }

        $meta = [
            'flarumMajor' => Targets::FLARUM_MAJOR,
            'total' => count($extensions),
            'ready' => $ready,
            'actionable' => $actionable,
            'blocked' => $blocked,
            'tasks' => $actionable - $blocked,
            'unknown' => $unknown,
            'extensions' => $extensions,
        ];

        $current = "$actionable / ".count($extensions);

        // Only an extension with no 2.0 path blocks the upgrade. Removals and
        // replacements are work with a clear path, and unchecked extensions
        // aren't known to be broken, so both are warnings.
        if ($blocked > 0) {
            return CheckResult::fail($current, $meta);
        }

        if ($actionable > 0 || $unknown > 0) {
            return CheckResult::warning($current, $meta);
        }

        return CheckResult::pass($current, $meta);
    }

    /**
     * Resolve a single extension's status, applying source precedence:
     *
     *   1. Curated superseded list (into-core / replaced).
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
            'id' => $extension->getId(),
            'name' => $packageName,
            'title' => $extension->getTitle(),
            'installedVersion' => $extension->getVersion(),
            'reason' => null,
            'replacement' => null,
            'replacementCompatible' => null,
            'compatibleVersion' => null,
            'latestVersion' => null,
            'source' => null,
            'contact' => $this->contact($extension),
        ];

        // 1. Curated superseded list.
        $superseded = $this->superseded->get($packageName);

        if ($superseded !== null) {
            return array_merge($entry, [
                'status' => 'superseded',
                'reason' => $superseded['reason'],
                'replacement' => $superseded['replacement'],
                'replacementCompatible' => $this->replacementCompatible($superseded['replacement']),
                'source' => 'superseded_list',
            ]);
        }

        // 2a. Abandoned status (authoritative, includes replacement): the
        // flarum/abandoned-extensions list first, as core does, then composer's
        // abandoned field. Both give true (no replacement) or the replacement
        // package name; composerAbandoned() gives false when neither applies.
        $listed = $this->abandoned !== null ? $this->abandoned->status($packageName) : null;
        $abandoned = $listed !== null ? $listed : $this->composerAbandoned($extension);
        $abandonedSource = $listed !== null ? 'abandoned_list' : 'composer_abandoned';

        if ($abandoned !== false) {
            // Composer's replacement may carry a version constraint (e.g.
            // "acme/foo:^2.0"); keep only the package name.
            $replacement = is_string($abandoned) && $abandoned !== ''
                ? explode(':', $abandoned, 2)[0]
                : null;

            return array_merge($entry, [
                'status' => 'abandoned',
                'replacement' => $replacement,
                'replacementCompatible' => $this->replacementCompatible($replacement),
                'source' => $abandonedSource,
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
        [$compat, $source] = $this->lookup($packageName);

        // A published compatible release always wins.
        if ($compat['status'] === 'compatible') {
            return array_merge($entry, [
                'status' => 'compatible',
                'compatibleVersion' => $compat['compatible_version'],
                'latestVersion' => $compat['latest_version'],
                'source' => $source,
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
                'status' => 'incompatible',
                'latestVersion' => $compat['latest_version'],
                'source' => $source,
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
                if (! is_array($author)) {
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
                    'name' => $name,
                    'email' => $email,
                    'homepage' => $homepage,
                ];
            }
        }

        return [
            'forum' => $clean($extension->composerJsonAttribute('support.forum')),
            'issues' => $clean($extension->composerJsonAttribute('support.issues')),
            'source' => $clean($extension->composerJsonAttribute('support.source')),
            'authors' => $authors,
        ];
    }

    /**
     * Look a package up on Packagist, then in each configured private
     * repository until one of them knows it.
     *
     * @return array{0: array<string, mixed>, 1: string} The compatibility result
     *                                                 (with at least status, compatible_version
     *                                                 and latest_version) and the source that answered.
     */
    protected function lookup(string $packageName): array
    {
        $compat = $this->packagist->compatibility($packageName, self::TARGET_CORE_VERSION);

        if ($compat['status'] !== 'unknown') {
            return [$compat, 'packagist'];
        }

        foreach ($this->repositories->all() as $repo) {
            $repoCompat = $this->composer->compatibility($repo, $packageName, self::TARGET_CORE_VERSION);

            if ($repoCompat['status'] !== 'unknown') {
                return [$repoCompat, $repo['type'] === RepositoryConfig::TYPE_FLOXUM ? 'floxum' : 'composer'];
            }
        }

        return [$compat, 'packagist'];
    }

    /**
     * Determine whether a suggested replacement package has a target-compatible
     * release, from the same sources as installed extensions.
     *
     * @return bool|null True/false when a source could answer, null when there
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

        [$compat] = $this->lookup($package);

        if ($compat['status'] === 'compatible') {
            return true;
        }

        if ($compat['status'] === 'incompatible') {
            return false;
        }

        return null;
    }

    /**
     * Composer's abandoned flag for an installed extension.
     *
     * Core 1.8.12+ exposes it as Extension::getAbandoned(). Older cores don't,
     * but they build each Extension from its vendor/composer/installed.json
     * entry, which carries the same field, so read it there with core's rules.
     *
     * @return string|bool False if not abandoned, true if abandoned with no
     *                     replacement, or the replacement package name.
     */
    protected function composerAbandoned(Extension $extension)
    {
        if ($this->coreReportsAbandoned($extension)) {
            return $extension->getAbandoned();
        }

        $abandoned = $extension->composerJsonAttribute('abandoned');

        if (is_string($abandoned) && $abandoned !== '') {
            return $abandoned;
        }

        if ($abandoned === true) {
            // As core does: packages from flarum.org/composer may carry an
            // unreliable abandoned flag, so only trust it from elsewhere.
            return strpos((string) $extension->composerJsonAttribute('dist.url'), 'flarum.org/composer') === false;
        }

        return false;
    }

    /**
     * Whether core provides Extension::getAbandoned() (added in 1.8.12).
     */
    protected function coreReportsAbandoned(Extension $extension): bool
    {
        return method_exists($extension, 'getAbandoned');
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
