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

use FoF\UpgradeAdvisor\ExtensionAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ExtensionActionTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function entry(array $overrides): array
    {
        return array_merge([
            'status' => 'compatible',
            'reason' => null,
            'replacement' => null,
            'replacementCompatible' => null,
        ], $overrides);
    }

    #[Test]
    #[DataProvider('entries')]
    public function classifies_each_entry_into_an_action_group(array $overrides, string $expected)
    {
        $this->assertSame($expected, ExtensionAction::for($this->entry($overrides)));
    }

    public static function entries(): array
    {
        return [
            'compatible' => [['status' => 'compatible'], ExtensionAction::NONE],
            'built into core' => [['status' => 'superseded', 'reason' => 'into_core'], ExtensionAction::REMOVE],
            'replaced by another package' => [['status' => 'superseded', 'reason' => 'replaced', 'replacement' => 'flarum/realtime'], ExtensionAction::SWAP_AFTER_UPGRADE],
            'abandoned, replacement ready' => [['status' => 'abandoned', 'replacement' => 'blazite/flarum-turnstile', 'replacementCompatible' => true], ExtensionAction::SWITCH_REPLACEMENT],
            'abandoned, replacement readiness unknown' => [['status' => 'abandoned', 'replacement' => 'acme/foo', 'replacementCompatible' => null], ExtensionAction::SWITCH_REPLACEMENT],
            'abandoned, replacement not ready' => [['status' => 'abandoned', 'replacement' => 'acme/foo', 'replacementCompatible' => false], ExtensionAction::NO_PATH],
            'abandoned, no replacement' => [['status' => 'abandoned'], ExtensionAction::NO_PATH],
            'incompatible' => [['status' => 'incompatible'], ExtensionAction::NO_PATH],
            'unknown' => [['status' => 'unknown'], ExtensionAction::UNKNOWN],
        ];
    }

    #[Test]
    public function only_pre_upgrade_work_counts_as_blocking()
    {
        $blocking = array_filter(ExtensionAction::ALL, [ExtensionAction::class, 'isBlocking']);

        // Also checks ALL's order: blockers first, as the admin page lists them.
        $this->assertSame([
            ExtensionAction::NO_PATH,
            ExtensionAction::REMOVE,
            ExtensionAction::SWAP_AFTER_UPGRADE,
            ExtensionAction::SWITCH_REPLACEMENT,
        ], array_values($blocking));
    }

    #[Test]
    public function ready_means_nothing_to_do_before_upgrading()
    {
        $this->assertTrue(ExtensionAction::isReady(ExtensionAction::NONE));
        $this->assertFalse(ExtensionAction::isReady(ExtensionAction::UNKNOWN));
        $this->assertFalse(ExtensionAction::isReady(ExtensionAction::REMOVE));
    }

    #[Test]
    #[DataProvider('hints')]
    public function picks_a_hint_and_its_parameters_for_each_entry(array $overrides, string $key, array $params)
    {
        $this->assertSame(['key' => $key, 'params' => $params + ['target' => '3.0']], ExtensionAction::hint($this->entry($overrides), '3.0'));
    }

    public static function hints(): array
    {
        return [
            'compatible' => [['status' => 'compatible', 'compatibleVersion' => '2.0.1'], 'none', ['version' => '2.0.1']],
            'compatible per discuss tag only' => [['status' => 'compatible', 'compatibleVersion' => null], 'none_unversioned', []],
            'built into core' => [['status' => 'superseded', 'reason' => 'into_core'], 'remove', []],
            'replaced' => [['status' => 'superseded', 'reason' => 'replaced', 'replacement' => 'flarum/realtime'], 'swap_after_upgrade', ['replacement' => 'flarum/realtime']],
            'abandoned, replacement ready' => [['status' => 'abandoned', 'replacement' => 'acme/new', 'replacementCompatible' => true], 'switch_replacement', ['replacement' => 'acme/new']],
            'abandoned, replacement unverified' => [['status' => 'abandoned', 'replacement' => 'acme/new', 'replacementCompatible' => null], 'switch_replacement_unverified', ['replacement' => 'acme/new']],
            'abandoned, replacement not ready' => [['status' => 'abandoned', 'replacement' => 'acme/new', 'replacementCompatible' => false], 'no_path_replacement_not_ready', ['replacement' => 'acme/new']],
            'abandoned, no replacement' => [['status' => 'abandoned'], 'no_path_abandoned', []],
            'incompatible with a known latest' => [['status' => 'incompatible', 'latestVersion' => '1.2.0'], 'no_path', ['version' => '1.2.0']],
            'incompatible per discuss tag only' => [['status' => 'incompatible', 'latestVersion' => null], 'no_path_unversioned', []],
            'unknown' => [['status' => 'unknown'], 'unknown', []],
        ];
    }

    #[Test]
    public function every_hint_has_an_english_translation()
    {
        $translations = Yaml::parseFile(__DIR__.'/../../locale/en.yml')['fof-upgrade-advisor']['admin']['hints'];

        foreach (self::hints() as $name => [$overrides]) {
            $key = ExtensionAction::hint($this->entry($overrides), '3.0')['key'];

            $this->assertArrayHasKey($key, $translations, "Missing translation for hint '$key' ($name)");
        }
    }

    #[Test]
    public function each_action_maps_to_the_readiness_bucket_shown_in_the_header()
    {
        $this->assertSame([
            ExtensionAction::NO_PATH => 'blocked',
            ExtensionAction::UNKNOWN => 'unknown',
            ExtensionAction::REMOVE => 'to_do',
            ExtensionAction::SWAP_AFTER_UPGRADE => 'to_do',
            ExtensionAction::SWITCH_REPLACEMENT => 'to_do',
            ExtensionAction::NONE => 'ready',
        ], array_combine(ExtensionAction::ALL, array_map([ExtensionAction::class, 'readiness'], ExtensionAction::ALL)));
    }
}
