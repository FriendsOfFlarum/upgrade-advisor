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

use FoF\UpgradeAdvisor\CacheGeneration;
use FoF\UpgradeAdvisor\Repository\ComposerRepository;
use GuzzleHttp\Client;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as Cache;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

class ComposerRepositoryTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    protected $tempDirs = [];

    protected function tearDown(): void
    {
        Mockery::close();

        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
    }

    protected function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/ua_test_'.uniqid();
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function fullRepo(): ComposerRepository
    {
        $cache = new Cache(new ArrayStore());
        $client = Mockery::mock(Client::class);
        $log = new NullLogger();
        $generation = new CacheGeneration($cache);

        return new ComposerRepository($client, $cache, $log, $generation);
    }

    protected function repo(): ComposerRepository
    {
        return $this->fullRepo();
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

    /** @test */
    public function absolute_url_resolves_file_url_templates()
    {
        $repoUrl = 'file:///path/to/satis/public/';

        // Path-relative
        $this->assertSame(
            'file:///path/to/satis/public/p2/%package%.json',
            $this->invoke('absoluteUrl', [$repoUrl, 'p2/%package%.json'])
        );

        // Root-relative
        $this->assertSame(
            'file:///path/to/satis/public/p2/%package%.json',
            $this->invoke('absoluteUrl', [$repoUrl, '/p2/%package%.json'])
        );

        // Full filesystem path matching repo base (e.g. Satis with homepage /path/to/satis/public)
        $this->assertSame(
            'file:///path/to/satis/public/p2/%package%.json',
            $this->invoke('absoluteUrl', [$repoUrl, '/path/to/satis/public/p2/%package%.json'])
        );

        // Already absolute file://
        $this->assertSame(
            'file:///path/to/satis/public/p2/%package%.json',
            $this->invoke('absoluteUrl', [$repoUrl, 'file:///path/to/satis/public/p2/%package%.json'])
        );
    }

    // ---- isLocal() & localPath() --------------------------------------------

    /** @test */
    public function is_local_identifies_file_urls_and_local_paths()
    {
        $this->assertTrue($this->invoke('isLocal', ['file:///path/to/satis/public']));
        $this->assertTrue($this->invoke('isLocal', ['/path/to/satis/public']));
        $this->assertTrue($this->invoke('isLocal', ['C:/path/to/satis/public']));
        $this->assertTrue($this->invoke('isLocal', ['C:\path\to\satis\public']));

        $this->assertFalse($this->invoke('isLocal', ['https://repo.packagist.com/acme/']));
        $this->assertFalse($this->invoke('isLocal', ['http://example.com/composer']));
    }

    /** @test */
    public function local_path_converts_file_urls_to_filesystem_paths()
    {
        $this->assertSame('/path/to/satis/public', $this->invoke('localPath', ['file:///path/to/satis/public']));
        $this->assertSame('/path/to/satis/public', $this->invoke('localPath', ['file://localhost/path/to/satis/public']));
        $this->assertSame('/path/to/my satis/public', $this->invoke('localPath', ['file:///path/to/my%20satis/public']));
        $this->assertSame('/path/to/satis/public', $this->invoke('localPath', ['file:///path/to/satis/public?foo=bar#section']));
        $this->assertSame('/path/to/satis/public', $this->invoke('localPath', ['/path/to/satis/public']));
    }

    /** @test */
    public function read_local_head_refuses_non_json_files()
    {
        $dir = $this->makeTempDir();
        $secretFile = $dir.'/config.php';
        file_put_contents($secretFile, '<?php return ["secret" => "value"];');

        $result = $this->invoke('readLocalHead', [$secretFile, 1024, $dir]);
        $this->assertNull($result, 'Must refuse to read non-JSON files for security');
    }

    /** @test */
    public function read_local_head_rejects_null_bytes()
    {
        $dir = $this->makeTempDir();
        $file = $dir.'/packages.json';
        file_put_contents($file, '{}');

        $result = $this->invoke('readLocalHead', [$file."\0.json", 1024, $dir]);
        $this->assertNull($result, 'Must reject paths with null bytes');
    }

    /** @test */
    public function read_local_head_blocks_path_traversal_outside_repo_base()
    {
        $baseDir = $this->makeTempDir();
        $outsideDir = $this->makeTempDir();

        $secretJson = $outsideDir.'/secret.json';
        file_put_contents($secretJson, '{"secret": "sensitive_data"}');

        // Attempt to traverse out of $baseDir into $outsideDir
        $traversalPath = $baseDir.'/../'.basename($outsideDir).'/secret.json';

        $result = $this->invoke('readLocalHead', [$traversalPath, 1024, $baseDir]);
        $this->assertNull($result, 'Must block path traversal escaping the repo base directory');
    }

    // ---- test() on local repository -----------------------------------------

    /** @test */
    public function test_returns_ok_for_valid_local_satis_repository()
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir.'/packages.json', json_encode(['packages' => []]));

        $repo = $this->fullRepo();
        $res = $repo->test(['type' => 'composer', 'url' => 'file://'.str_replace('\\', '/', $dir).'/', 'username' => null, 'token' => null]);

        $this->assertTrue($res['ok']);
        $this->assertSame('ok', $res['reason']);
    }

    /** @test */
    public function test_returns_unreachable_for_missing_local_file()
    {
        $dir = $this->makeTempDir();

        $repo = $this->fullRepo();
        $res = $repo->test(['type' => 'composer', 'url' => 'file://'.str_replace('\\', '/', $dir).'/', 'username' => null, 'token' => null]);

        $this->assertFalse($res['ok']);
        $this->assertSame('unreachable', $res['reason']);
    }

    /** @test */
    public function test_returns_invalid_for_malformed_local_json()
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir.'/packages.json', 'not-valid-json');

        $repo = $this->fullRepo();
        $res = $repo->test(['type' => 'composer', 'url' => 'file://'.str_replace('\\', '/', $dir).'/', 'username' => null, 'token' => null]);

        $this->assertFalse($res['ok']);
        $this->assertSame('invalid', $res['reason']);
    }

    // ---- compatibility() on local repository --------------------------------

    /** @test */
    public function compatibility_resolves_from_local_satis_lazy_p2()
    {
        $dir = $this->makeTempDir();
        mkdir($dir.'/p2/acme', 0777, true);

        file_put_contents($dir.'/packages.json', json_encode([
            'metadata-url' => 'p2/%package%.json',
        ]));

        file_put_contents($dir.'/p2/acme/foo.json', json_encode([
            'packages' => [
                'acme/foo' => [
                    [
                        'version' => '2.0.0',
                        'require' => ['flarum/core' => '^2.0'],
                    ],
                ],
            ],
        ]));

        $repo = $this->fullRepo();
        $res = $repo->compatibility(
            ['type' => 'composer', 'url' => 'file://'.str_replace('\\', '/', $dir).'/', 'username' => null, 'token' => null],
            'acme/foo',
            '2.0.0'
        );

        $this->assertSame('compatible', $res['status']);
        $this->assertSame('2.0.0', $res['compatible_version']);
    }

    /** @test */
    public function compatibility_resolves_from_local_satis_with_absolute_metadata_url()
    {
        $dir = $this->makeTempDir();
        $cleanDir = str_replace('\\', '/', $dir);
        mkdir($dir.'/p2/acme', 0777, true);

        // Satis outputs full filesystem path when homepage is set to /var/www/... or omitted
        file_put_contents($dir.'/packages.json', json_encode([
            'metadata-url' => $cleanDir.'/p2/%package%.json',
        ]));

        file_put_contents($dir.'/p2/acme/foo.json', json_encode([
            'packages' => [
                'acme/foo' => [
                    [
                        'version' => '2.0.0',
                        'require' => ['flarum/core' => '^2.0'],
                    ],
                ],
            ],
        ]));

        $repo = $this->fullRepo();
        $res = $repo->compatibility(
            ['type' => 'composer', 'url' => 'file://'.$cleanDir.'/', 'username' => null, 'token' => null],
            'acme/foo',
            '2.0.0'
        );

        $this->assertSame('compatible', $res['status']);
        $this->assertSame('2.0.0', $res['compatible_version']);
    }

    /** @test */
    public function compatibility_resolves_from_local_satis_inline()
    {
        $dir = $this->makeTempDir();

        file_put_contents($dir.'/packages.json', json_encode([
            'packages' => [
                'acme/foo' => [
                    '2.0.0' => [
                        'version' => '2.0.0',
                        'require' => ['flarum/core' => '^2.0'],
                    ],
                ],
            ],
        ]));

        $repo = $this->fullRepo();
        $res = $repo->compatibility(
            ['type' => 'composer', 'url' => 'file://'.str_replace('\\', '/', $dir).'/', 'username' => null, 'token' => null],
            'acme/foo',
            '2.0.0'
        );

        $this->assertSame('compatible', $res['status']);
        $this->assertSame('2.0.0', $res['compatible_version']);
    }

    /** @test */
    public function compatibility_resolves_from_local_satis_includes()
    {
        $dir = $this->makeTempDir();
        mkdir($dir.'/include', 0777, true);

        file_put_contents($dir.'/packages.json', json_encode([
            'includes' => [
                'include/all$hash.json' => ['sha1' => 'dummy'],
            ],
        ]));

        file_put_contents($dir.'/include/all$hash.json', json_encode([
            'packages' => [
                'acme/foo' => [
                    '2.0.0' => [
                        'version' => '2.0.0',
                        'require' => ['flarum/core' => '^2.0'],
                    ],
                ],
            ],
        ]));

        $repo = $this->fullRepo();
        $res = $repo->compatibility(
            ['type' => 'composer', 'url' => 'file://'.str_replace('\\', '/', $dir).'/', 'username' => null, 'token' => null],
            'acme/foo',
            '2.0.0'
        );

        $this->assertSame('compatible', $res['status']);
        $this->assertSame('2.0.0', $res['compatible_version']);
    }

    // ---- rootMetadataUrl() --------------------------------------------------

    /** @test */
    public function root_metadata_url_handles_escaped_slashes()
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir.'/packages.json', '{"metadata-url":"p2\\/%package%.json"}');

        $repo = $this->fullRepo();
        $m = new ReflectionMethod(ComposerRepository::class, 'rootMetadataUrl');
        $m->setAccessible(true);
        $url = $m->invoke($repo, ['type' => 'composer', 'url' => 'file://'.str_replace('\\', '/', $dir).'/', 'username' => null, 'token' => null]);

        $this->assertSame('p2/%package%.json', $url);
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
