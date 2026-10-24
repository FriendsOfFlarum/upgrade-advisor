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

use Flarum\Settings\SettingsRepositoryInterface;
use FoF\UpgradeAdvisor\Repository\RepositoryConfig;
use Mockery;
use PHPUnit\Framework\TestCase;

class RepositoryConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /** @test */
    public function normalise_normalises_floxum()
    {
        $res = RepositoryConfig::normalise(['type' => 'floxum', 'token' => 'my-token']);

        $this->assertSame([
            'type' => 'floxum',
            'url' => 'https://floxum.com/composer/',
            'username' => null,
            'token' => 'my-token',
        ], $res);

        $this->assertNull(RepositoryConfig::normalise(['type' => 'floxum', 'token' => '']));
    }

    /** @test */
    public function normalise_normalises_composer_http_and_file_urls()
    {
        // HTTP URL
        $res = RepositoryConfig::normalise([
            'type' => 'composer',
            'url' => 'https://repo.packagist.com/acme',
            'username' => 'user',
            'token' => 'secret',
        ]);

        $this->assertSame([
            'type' => 'composer',
            'url' => 'https://repo.packagist.com/acme/',
            'username' => 'user',
            'token' => 'secret',
        ], $res);

        // file:// URL
        $resFile = RepositoryConfig::normalise([
            'type' => 'composer',
            'url' => 'file:///path/to/satis/public',
        ]);

        $this->assertSame([
            'type' => 'composer',
            'url' => 'file:///path/to/satis/public/',
            'username' => null,
            'token' => null,
        ], $resFile);

        // Local absolute path starting with /
        $resPath = RepositoryConfig::normalise([
            'type' => 'composer',
            'url' => '/path/to/satis/public',
        ]);

        $this->assertSame([
            'type' => 'composer',
            'url' => 'file:///path/to/satis/public/',
            'username' => null,
            'token' => null,
        ], $resPath);

        // Windows absolute drive path
        $resWin = RepositoryConfig::normalise([
            'type' => 'composer',
            'url' => 'C:\path\to\satis\public',
        ]);

        $this->assertSame([
            'type' => 'composer',
            'url' => 'file:///C:/path/to/satis/public/',
            'username' => null,
            'token' => null,
        ], $resWin);
    }

    /** @test */
    public function normalise_rejects_dangerous_schemes_and_unc_paths()
    {
        // Stream wrappers and arbitrary protocols
        $this->assertNull(RepositoryConfig::normalise(['type' => 'composer', 'url' => 'phar:///var/www/archive.phar']));
        $this->assertNull(RepositoryConfig::normalise(['type' => 'composer', 'url' => 'php://filter/read=convert.base64/resource=config.php']));
        $this->assertNull(RepositoryConfig::normalise(['type' => 'composer', 'url' => 'data://text/plain;base64,SSBsb3ZlIFBIUAo=']));
        $this->assertNull(RepositoryConfig::normalise(['type' => 'composer', 'url' => 'gopher://attacker.com:70/']));
        $this->assertNull(RepositoryConfig::normalise(['type' => 'composer', 'url' => 'ftp://attacker.com/repo']));

        // UNC paths
        $this->assertNull(RepositoryConfig::normalise(['type' => 'composer', 'url' => '\\\\attacker.com\\share']));
        $this->assertNull(RepositoryConfig::normalise(['type' => 'composer', 'url' => '//attacker.com/share']));
        $this->assertNull(RepositoryConfig::normalise(['type' => 'composer', 'url' => 'file://attacker.com/share']));
    }

    /** @test */
    public function all_reads_and_normalises_settings()
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with('fof-upgrade-advisor.repositories')->andReturn(json_encode([
            ['type' => 'floxum', 'token' => 'token-123'],
            ['type' => 'composer', 'url' => 'https://repo.packagist.com/acme/'],
            ['type' => 'composer', 'url' => 'file:///path/to/satis/public'],
        ]));

        $config = new RepositoryConfig($settings);
        $all = $config->all();

        $this->assertCount(3, $all);
        $this->assertSame('floxum', $all[0]['type']);
        $this->assertSame('composer', $all[1]['type']);
        $this->assertSame('https://repo.packagist.com/acme/', $all[1]['url']);
        $this->assertSame('composer', $all[2]['type']);
        $this->assertSame('file:///path/to/satis/public/', $all[2]['url']);
    }
}
