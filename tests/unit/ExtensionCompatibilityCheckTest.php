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
use FoF\UpgradeAdvisor\CoreConstraint;
use FoF\UpgradeAdvisor\ExtensionAction;
use FoF\UpgradeAdvisor\Repository\ComposerRepository;
use FoF\UpgradeAdvisor\Repository\DiscussRepository;
use FoF\UpgradeAdvisor\Repository\PackagistRepository;
use FoF\UpgradeAdvisor\Repository\RepositoryConfig;
use FoF\UpgradeAdvisor\SupersededExtensions;
use FoF\UpgradeAdvisor\Target;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExtensionCompatibilityCheckTest extends TestCase
{
    /**
     * A superseded mapping, as the Superseded extender would register it.
     */
    protected const INTO_CORE = ['acme/nightmode' => ['reason' => SupersededExtensions::INTO_CORE]];

    protected function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * @param array<string, array{status?: string, constraint?: string, compatible_version?: ?string, latest_version?: ?string}> $packagist
     *                                                                                                    A "constraint" (the flarum/core requirement of the package's releases) is evaluated
     *                                                                                                    against the target version instead of giving a fixed status.
     * @param array<string, array{status: string, compatible_version?: ?string, latest_version?: ?string}> $private   Packages in one configured private repository.
     * @param array<string, string|true>                                                                  $abandoned Entries on the flarum/abandoned-extensions list.
     * @param array<string, string|true>                                                                  $composerAbandoned Packages whose own composer metadata marks them abandoned.
     * @param array<string, array{abandoned: bool, majors: int[]}>                                       $signals           Tags on a package's discuss support thread.
     */
    protected function check(array $packagist, array $superseded = [], array $private = [], array $abandoned = [], array $composerAbandoned = [], array $signals = [], ?Target $target = null): ExtensionCompatibilityCheck
    {
        $target ??= new Target('3.0.0', '8.4.0');
        $extensions = [];
        $forumUrls = [];

        foreach (array_keys($packagist) as $name) {
            $forumUrls[] = $forum = isset($signals[$name]) ? 'https://discuss.flarum.org/d/'.$name : null;

            $extension = new Extension('/tmp/'.$name, [
                'name' => $name,
                'version' => '1.0.0',
                'extra' => ['flarum-extension' => ['title' => $name]],
                'support' => ['forum' => $forum],
            ]);
            $extension->setVersion('1.0.0');

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
        $packagistRepo->shouldReceive('compatibility')->andReturnUsing(function (string $name, string $coreVersion) use ($packagist) {
            $entry = $packagist[$name] ?? ['status' => 'unknown'];

            if (isset($entry['constraint'])) {
                $entry['status'] = CoreConstraint::supports($entry['constraint'], $coreVersion) ? 'compatible' : 'incompatible';
            }

            return $entry + ['compatible_version' => null, 'latest_version' => null, 'error' => false];
        });

        $discuss = Mockery::mock(DiscussRepository::class);
        $discuss->shouldReceive('prefetch')->once()->with($forumUrls);
        $discuss->shouldReceive('signals')->andReturnUsing(function (?string $url) use ($signals) {
            return $url === null ? null : $signals[substr($url, strlen('https://discuss.flarum.org/d/'))];
        });

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

        return new ExtensionCompatibilityCheck($manager, $packagistRepo, $discuss, $repositories, $composer, new SupersededExtensions($superseded), $abandonedList, $target);
    }

    #[Test]
    public function each_extension_carries_its_action_and_the_meta_counts_them()
    {
        $result = $this->check([
            'acme/ready' => ['status' => 'compatible', 'compatible_version' => '3.0.0'],
            'acme/nightmode' => ['status' => 'unknown'],
            'fof/upgrade-advisor' => ['status' => 'compatible', 'compatible_version' => '3.0.0'],
            'acme/stuck' => ['status' => 'incompatible', 'latest_version' => '1.2.0'],
            'acme/private' => ['status' => 'unknown'],
        ], self::INTO_CORE)->run();

        $actions = array_column($result->meta['extensions'], 'action', 'name');

        $this->assertSame([
            'acme/ready' => ExtensionAction::NONE,
            'acme/nightmode' => ExtensionAction::REMOVE,
            'fof/upgrade-advisor' => ExtensionAction::NONE,
            'acme/stuck' => ExtensionAction::NO_PATH,
            'acme/private' => ExtensionAction::UNKNOWN,
        ], $actions);

        $hints = array_column($result->meta['extensions'], 'hint', 'name');
        $this->assertSame(['key' => 'no_path', 'params' => ['version' => '1.2.0', 'target' => '3.0']], $hints['acme/stuck']);

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
            ['acme/translate-next' => ['status' => 'compatible', 'compatible_version' => '3.0.0']]
        )->run();

        $this->assertTrue($result->meta['extensions'][0]['replacementCompatible']);
    }

    #[Test]
    public function the_abandoned_extensions_list_flags_extensions_packagist_does_not()
    {
        $result = $this->check(
            [
                'acme/emoji' => ['status' => 'incompatible', 'latest_version' => '1.1.1'],
                'acme/flamoji' => ['status' => 'compatible', 'compatible_version' => '3.0.0'],
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
        $result = $this->check($packagist, self::INTO_CORE)->run();

        $this->assertSame($status, $result->status);
        $this->assertSame($blocked, $result->meta['blocked']);
        $this->assertSame($tasks, $result->meta['tasks']);
    }

    public static function outcomes(): array
    {
        $ready = ['acme/ready' => ['status' => 'compatible', 'compatible_version' => '3.0.0']];

        return [
            'all ready' => [$ready, CheckResult::PASS, 0, 0],
            'a removal left to do' => [$ready + ['acme/nightmode' => ['status' => 'unknown']], CheckResult::WARNING, 0, 1],
            'an extension that could not be checked' => [$ready + ['acme/private' => ['status' => 'unknown']], CheckResult::WARNING, 0, 0],
            'an extension with no path to the target' => [$ready + ['acme/nightmode' => ['status' => 'unknown'], 'acme/stuck' => ['status' => 'incompatible']], CheckResult::FAIL, 1, 1],
        ];
    }

    #[Test]
    public function the_source_says_why_an_extension_was_flagged()
    {
        $sources = array_column($this->check(
            [
                'acme/nightmode' => ['status' => 'unknown'],
                'acme/listed' => ['status' => 'incompatible'],
                'acme/flagged' => ['status' => 'incompatible'],
                'acme/ready' => ['status' => 'compatible', 'compatible_version' => '3.0.0'],
            ],
            self::INTO_CORE,
            [],
            ['acme/listed' => 'acme/listed-next'],
            ['acme/flagged' => 'acme/flagged-next']
        )->run()->meta['extensions'], 'source', 'name');

        $this->assertSame([
            'acme/nightmode' => 'superseded_list',
            'acme/listed' => 'abandoned_list',
            'acme/flagged' => 'composer_abandoned',
            'acme/ready' => 'packagist',
        ], $sources);
    }

    #[Test]
    public function compatibility_is_judged_against_the_target_version()
    {
        $packagist = [
            'acme/pinned' => ['constraint' => '~2.0.0'],
            'acme/caret' => ['constraint' => '^2.0'],
        ];

        $minor = array_column($this->check($packagist, target: new Target('2.1.0', '8.3.0'))->run()->meta['extensions'], 'status', 'name');
        $major = array_column($this->check($packagist)->run()->meta['extensions'], 'status', 'name');

        $this->assertSame(['acme/pinned' => 'incompatible', 'acme/caret' => 'compatible'], $minor);
        $this->assertSame(['acme/pinned' => 'incompatible', 'acme/caret' => 'incompatible'], $major);
    }

    #[Test]
    public function the_report_and_its_hints_name_the_target()
    {
        $result = $this->check(['acme/stuck' => ['status' => 'incompatible', 'latest_version' => '1.2.0']])->run();

        $this->assertSame('3.0', $result->meta['target']);
        $this->assertSame(['version' => '1.2.0', 'target' => '3.0'], $result->meta['extensions'][0]['hint']['params']);
    }

    #[Test]
    public function for_a_new_major_the_discuss_version_tags_settle_extensions_no_repository_knows()
    {
        $result = $this->check(
            ['acme/tagged' => ['status' => 'unknown'], 'acme/old' => ['status' => 'unknown']],
            signals: [
                'acme/tagged' => ['abandoned' => false, 'majors' => [2, 3]],
                'acme/old' => ['abandoned' => false, 'majors' => [2]],
            ]
        )->run();

        $entries = array_column($result->meta['extensions'], null, 'name');

        $this->assertSame(['compatible', 'discuss'], [$entries['acme/tagged']['status'], $entries['acme/tagged']['source']]);
        $this->assertSame(['incompatible', 'discuss'], [$entries['acme/old']['status'], $entries['acme/old']['source']]);
    }

    #[Test]
    public function for_a_minor_the_discuss_version_tags_say_nothing()
    {
        // Every 2.x extension is tagged version-2x, which can't vouch for 2.1.
        $result = $this->check(
            ['acme/tagged' => ['status' => 'unknown']],
            signals: ['acme/tagged' => ['abandoned' => false, 'majors' => [2]]],
            target: new Target('2.1.0', '8.3.0')
        )->run();

        $this->assertSame('unknown', $result->meta['extensions'][0]['status']);
    }
}
