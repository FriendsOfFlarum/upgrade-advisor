<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Tests\unit;

use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;
use FoF\UpgradeAdvisor\AbandonedExtensions;
use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\Check\Checks\ExtensionCompatibilityCheck;
use FoF\UpgradeAdvisor\ExtensionAction;
use FoF\UpgradeAdvisor\Repository\ComposerRepository;
use FoF\UpgradeAdvisor\Repository\DiscussRepository;
use FoF\UpgradeAdvisor\Repository\PackagistRepository;
use FoF\UpgradeAdvisor\Repository\RepositoryConfig;
use FoF\UpgradeAdvisor\SupersededExtensions;
use Mockery;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ExtensionCompatibilityCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * @param array<string, array{status: string, compatible_version?: ?string, latest_version?: ?string}> $packagist
     * @param array<string, array{status: string, compatible_version?: ?string, latest_version?: ?string}> $private   Packages in one configured private repository.
     * @param array<string, string|true>                                                                  $abandoned Entries on the flarum/abandoned-extensions list.
     * @param array<string, string|true>                                                                  $composerAbandoned Packages whose own composer metadata marks them abandoned.
     * @param array<string, array<string, mixed>>                                                         $installedJson     Extra fields in a package's installed.json entry.
     * @param bool                                                                                        $oldCore           Simulate core before 1.8.12, which has no Extension::getAbandoned().
     */
    protected function check(array $packagist, array $superseded = [], array $private = [], array $abandoned = [], array $composerAbandoned = [], array $installedJson = [], bool $oldCore = false): ExtensionCompatibilityCheck
    {
        $extensions = [];

        foreach (array_keys($packagist) as $name) {
            $extension = new Extension('/tmp/'.$name, array_merge([
                'name' => $name,
                'version' => '1.0.0',
                'extra' => ['flarum-extension' => ['title' => $name]],
            ], $installedJson[$name] ?? []));

            if (isset($composerAbandoned[$name])) {
                $extension->setAbandoned($composerAbandoned[$name]);
            }

            $extensions[] = $extension;
        }

        $manager = Mockery::mock(ExtensionManager::class);
        $manager->shouldReceive('getExtensions')->andReturn(collect($extensions));

        $packagistRepo = Mockery::mock(PackagistRepository::class);
        // One concurrent batch up front, covering every installed package.
        $packagistRepo->shouldReceive('prefetch')->once()->with(array_keys($packagist));
        $packagistRepo->shouldReceive('compatibility')->andReturnUsing(function (string $name) use ($packagist) {
            return ($packagist[$name] ?? ['status' => 'unknown']) + ['compatible_version' => null, 'latest_version' => null, 'error' => false];
        });

        $discuss = Mockery::mock(DiscussRepository::class);
        $discuss->shouldReceive('prefetch')->once()->with(array_fill(0, count($packagist), null));
        $discuss->shouldReceive('signals')->andReturn(null);

        $repositories = Mockery::mock(RepositoryConfig::class);
        $repo = ['type' => RepositoryConfig::TYPE_COMPOSER, 'url' => 'https://private.example.com', 'username' => null, 'token' => 'secret'];
        $repositories->shouldReceive('all')->andReturn($private ? [$repo] : []);

        $composer = Mockery::mock(ComposerRepository::class);
        $composer->shouldReceive('compatibility')->andReturnUsing(function (array $repo, string $name) use ($private) {
            return ($private[$name] ?? ['status' => 'unknown']) + ['compatible_version' => null, 'latest_version' => null, 'error' => false];
        });

        $abandonedList = Mockery::mock(AbandonedExtensions::class);
        $abandonedList->shouldReceive('status')->andReturnUsing(function (string $name) use ($abandoned) {
            return $abandoned[$name] ?? null;
        });

        $this->addToAssertionCount(2); // the once() expectations above, verified in tearDown

        $args = [$manager, $packagistRepo, $discuss, $repositories, $composer, new SupersededExtensions($superseded), $abandonedList];

        if (! $oldCore) {
            return new ExtensionCompatibilityCheck(...$args);
        }

        return new class(...$args) extends ExtensionCompatibilityCheck {
            protected function coreReportsAbandoned(Extension $extension): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function each_extension_carries_its_action_and_the_meta_counts_them()
    {
        $result = $this->check([
            'acme/ready' => ['status' => 'compatible', 'compatible_version' => '2.0.0'],
            'fof/nightmode' => ['status' => 'unknown'], // curated: built into core
            'fof/upgrade-advisor' => ['status' => 'unknown'], // curated: the advisor itself
            'acme/stuck' => ['status' => 'incompatible', 'latest_version' => '1.2.0'],
            'acme/private' => ['status' => 'unknown'],
        ])->run();

        $actions = array_column($result->meta['extensions'], 'action', 'name');

        $this->assertSame([
            'acme/ready' => ExtensionAction::NONE,
            'fof/nightmode' => ExtensionAction::REMOVE,
            'fof/upgrade-advisor' => ExtensionAction::REMOVE_LAST,
            'acme/stuck' => ExtensionAction::NO_PATH,
            'acme/private' => ExtensionAction::UNKNOWN,
        ], $actions);

        $hints = array_column($result->meta['extensions'], 'hint', 'name');
        $this->assertSame(['key' => 'no_path', 'params' => ['version' => '1.2.0']], $hints['acme/stuck']);

        $this->assertSame(5, $result->meta['total']);
        $this->assertSame(2, $result->meta['ready']);
        $this->assertSame(2, $result->meta['actionable']);
        $this->assertSame(1, $result->meta['unknown']);
    }

    #[Test]
    public function extender_supplied_mappings_are_treated_as_superseded()
    {
        $result = $this->check(
            ['acme/translate' => ['status' => 'unknown']],
            [
                'acme/translate' => [
                    'reason' => SupersededExtensions::REPLACED,
                    'replacement' => 'acme/translate-next',
                ],
            ]
        )->run();

        $entry = $result->meta['extensions'][0];

        $this->assertSame('superseded', $entry['status']);
        $this->assertSame('acme/translate-next', $entry['replacement']);
        $this->assertSame(ExtensionAction::SWAP_AFTER_UPGRADE, $entry['action']);

        // Superseded entries are blocking, so they count as actionable.
        $this->assertSame(1, $result->meta['actionable']);
    }

    #[Test]
    public function a_replacement_only_in_a_private_repository_is_still_checked_for_readiness()
    {
        $result = $this->check(
            ['acme/translate' => ['status' => 'unknown']],
            ['acme/translate' => ['reason' => SupersededExtensions::REPLACED, 'replacement' => 'acme/translate-next']],
            ['acme/translate-next' => ['status' => 'compatible', 'compatible_version' => '2.0.0']]
        )->run();

        $this->assertTrue($result->meta['extensions'][0]['replacementCompatible']);
    }

    #[Test]
    public function the_abandoned_extensions_list_flags_extensions_packagist_does_not()
    {
        $result = $this->check(
            [
                'acme/emoji' => ['status' => 'incompatible', 'latest_version' => '1.1.1'],
                'acme/flamoji' => ['status' => 'compatible', 'compatible_version' => '2.0.0'],
            ],
            [],
            [],
            ['acme/emoji' => 'acme/flamoji']
        )->run();

        $entry = array_column($result->meta['extensions'], null, 'name')['acme/emoji'];

        $this->assertSame('abandoned', $entry['status']);
        $this->assertSame('acme/flamoji', $entry['replacement']);
        $this->assertTrue($entry['replacementCompatible']);
        $this->assertSame(ExtensionAction::SWITCH_REPLACEMENT, $entry['action']);
    }

    #[Test]
    #[DataProvider('outcomes')]
    public function only_extensions_with_no_path_block_the_upgrade(array $packagist, string $status, int $blocked, int $tasks)
    {
        $result = $this->check($packagist)->run();

        $this->assertSame($status, $result->status);
        $this->assertSame($blocked, $result->meta['blocked']);
        $this->assertSame($tasks, $result->meta['tasks']);
    }

    public static function outcomes(): array
    {
        $ready = ['acme/ready' => ['status' => 'compatible', 'compatible_version' => '2.0.0']];

        return [
            'all ready' => [$ready, CheckResult::PASS, 0, 0],
            'a removal left to do' => [$ready + ['fof/nightmode' => ['status' => 'unknown']], CheckResult::WARNING, 0, 1],
            'an extension that could not be checked' => [$ready + ['acme/private' => ['status' => 'unknown']], CheckResult::WARNING, 0, 0],
            'an extension with no 2.0 path' => [$ready + ['fof/nightmode' => ['status' => 'unknown'], 'acme/stuck' => ['status' => 'incompatible']], CheckResult::FAIL, 1, 1],
        ];
    }

    #[Test]
    public function the_source_says_why_an_extension_was_flagged()
    {
        $sources = array_column($this->check(
            [
                'fof/nightmode' => ['status' => 'unknown'],
                'acme/listed' => ['status' => 'incompatible'],
                'acme/flagged' => ['status' => 'incompatible'],
                'acme/ready' => ['status' => 'compatible', 'compatible_version' => '2.0.0'],
            ],
            [],
            [],
            ['acme/listed' => 'acme/listed-next'],
            ['acme/flagged' => 'acme/flagged-next']
        )->run()->meta['extensions'], 'source', 'name');

        $this->assertSame([
            'fof/nightmode' => 'superseded_list',
            'acme/listed' => 'abandoned_list',
            'acme/flagged' => 'composer_abandoned',
            'acme/ready' => 'packagist',
        ], $sources);
    }

    #[Test]
    public function on_core_before_1_8_12_abandoned_status_comes_from_installed_json()
    {
        $result = $this->check(
            [
                'acme/replaced' => ['status' => 'incompatible'],
                'acme/dead' => ['status' => 'incompatible'],
                'acme/ready' => ['status' => 'compatible', 'compatible_version' => '2.0.0'],
            ],
            [],
            [],
            [],
            [],
            [
                'acme/replaced' => ['abandoned' => 'acme/replaced-next'],
                'acme/dead' => ['abandoned' => true],
            ],
            true
        )->run();

        $entries = array_column($result->meta['extensions'], null, 'name');

        $this->assertSame('abandoned', $entries['acme/replaced']['status']);
        $this->assertSame('acme/replaced-next', $entries['acme/replaced']['replacement']);
        $this->assertSame('composer_abandoned', $entries['acme/replaced']['source']);

        $this->assertSame('abandoned', $entries['acme/dead']['status']);
        $this->assertNull($entries['acme/dead']['replacement']);

        $this->assertSame('compatible', $entries['acme/ready']['status']);
    }

    #[Test]
    public function on_core_before_1_8_12_flarum_marketplace_abandoned_flags_are_ignored_as_core_does()
    {
        // Packages from flarum.org/composer can carry an unreliable `abandoned: true`;
        // core 1.8.12+ skips those, so the fallback must too.
        $result = $this->check(
            ['acme/premium' => ['status' => 'compatible', 'compatible_version' => '2.0.0']],
            [],
            [],
            [],
            [],
            ['acme/premium' => ['abandoned' => true, 'dist' => ['url' => 'https://flarum.org/composer/dists/acme/premium.zip']]],
            true
        )->run();

        $this->assertSame('compatible', $result->meta['extensions'][0]['status']);
    }
}
