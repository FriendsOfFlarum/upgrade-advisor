<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Tests\unit;

use FoF\UpgradeAdvisor\Repository\ComposerRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ComposerRepositoryTest extends TestCase
{
    protected function repo(): ComposerRepository
    {
        // expandMinified() and absoluteUrl() never touch the injected services.
        return (new \ReflectionClass(ComposerRepository::class))->newInstanceWithoutConstructor();
    }

    protected function invoke(string $method, array $args)
    {
        $m = new ReflectionMethod(ComposerRepository::class, $method);
        $m->setAccessible(true);

        return $m->invoke($this->repo(), ...$args);
    }

    // ---- expandMinified() ---------------------------------------------------

    /** @test */
    public function expand_minified_carries_forward_unchanged_fields()
    {
        // Composer "composer/2.0" minified list: entry 0 is complete, later
        // entries are deltas. "require" is only declared on entry 0 here, so it
        // must be carried forward to entries 1 and 2.
        $versions = [
            ['version' => '2.0.0', 'require' => ['flarum/core' => '^2.0']],
            ['version' => '1.9.0'],
            ['version' => '1.8.0'],
        ];

        $expanded = $this->invoke('expandMinified', [$versions]);

        $this->assertCount(3, $expanded);
        $this->assertSame('^2.0', $expanded[0]['require']['flarum/core']);
        $this->assertSame('^2.0', $expanded[1]['require']['flarum/core'], 'require should carry forward');
        $this->assertSame('^2.0', $expanded[2]['require']['flarum/core']);
        $this->assertSame('1.9.0', $expanded[1]['version']);
    }

    /** @test */
    public function expand_minified_applies_field_changes_in_later_entries()
    {
        $versions = [
            ['version' => '2.0.0', 'require' => ['flarum/core' => '^2.0']],
            ['version' => '1.0.0', 'require' => ['flarum/core' => '^1.8']],
        ];

        $expanded = $this->invoke('expandMinified', [$versions]);

        $this->assertSame('^2.0', $expanded[0]['require']['flarum/core']);
        $this->assertSame('^1.8', $expanded[1]['require']['flarum/core'], 'a redeclared field should override');
    }

    /** @test */
    public function expand_minified_removes_fields_marked_unset()
    {
        $versions = [
            ['version' => '2.0.0', 'require' => ['flarum/core' => '^2.0'], 'extra' => ['foo' => 'bar']],
            ['version' => '1.0.0', 'extra' => '__unset'],
        ];

        $expanded = $this->invoke('expandMinified', [$versions]);

        $this->assertArrayHasKey('extra', $expanded[0]);
        $this->assertArrayNotHasKey('extra', $expanded[1], '__unset should remove the field');
        $this->assertSame('^2.0', $expanded[1]['require']['flarum/core'], 'other fields still carry forward');
    }

    // ---- absoluteUrl() ------------------------------------------------------

    /** @test */
    public function absolute_url_returns_fully_qualified_urls_unchanged()
    {
        $result = $this->invoke('absoluteUrl', ['https://repo.packagist.com/acme/', 'https://cdn.example.com/p2/%package%.json']);

        $this->assertSame('https://cdn.example.com/p2/%package%.json', $result);
    }

    /** @test */
    public function absolute_url_resolves_root_relative_against_scheme_and_host_only()
    {
        // metadata-url like "/acme/p2/%package%.json" is host-absolute: it must
        // resolve against scheme+host, NOT be appended to the repo path (which
        // would duplicate "/acme").
        $result = $this->invoke('absoluteUrl', ['https://repo.packagist.com/acme/', '/acme/p2/%package%.json']);

        $this->assertSame('https://repo.packagist.com/acme/p2/%package%.json', $result);
    }

    /** @test */
    public function absolute_url_appends_path_relative_templates_to_the_repo_url()
    {
        $result = $this->invoke('absoluteUrl', ['https://repo.packagist.com/acme/', 'p2/%package%.json']);

        $this->assertSame('https://repo.packagist.com/acme/p2/%package%.json', $result);
    }

    // ---- isDev() ------------------------------------------------------------

    /** @test */
    public function is_dev_only_matches_dev_branch_versions_not_prereleases()
    {
        $this->assertTrue($this->invoke('isDev', ['dev-main']));
        $this->assertTrue($this->invoke('isDev', ['1.x-dev']));

        // Pre-releases are NOT dev — they count as real releases.
        $this->assertFalse($this->invoke('isDev', ['2.0.0-beta.1']));
        $this->assertFalse($this->invoke('isDev', ['2.0.0-rc.4']));
        $this->assertFalse($this->invoke('isDev', ['1.8.0']));
    }
}
