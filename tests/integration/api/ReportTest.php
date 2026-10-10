<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Tests\integration\api;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use FoF\UpgradeAdvisor\Check\Checks\DatabaseVersionCheck;
use FoF\UpgradeAdvisor\Check\Checks\PhpVersionCheck;
use FoF\UpgradeAdvisor\Target;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ReportTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-upgrade-advisor');

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
    }

    /**
     * Wake the advisor for a 3.0 target, running only the given checks so the
     * test never reaches Packagist or discuss.
     *
     * @param class-string[] $checks
     */
    protected function targeting(string $phpMinimum, array $checks = [PhpVersionCheck::class]): void
    {
        $this->extend(new class($phpMinimum, $checks) implements ExtenderInterface {
            public function __construct(protected string $phpMinimum, protected array $checks)
            {
            }

            public function extend(Container $container, ?Extension $extension = null): void
            {
                $container->instance('fof-upgrade-advisor.target', new Target('3.0.0', $this->phpMinimum));
                $container->extend('fof-upgrade-advisor.checks', fn () => $this->checks);
            }
        });
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function only_admins_can_see_the_report(string $method, string $path)
    {
        $response = $this->send($this->request($method, $path, ['authenticatedAs' => 2]));

        $this->assertSame(403, $response->getStatusCode());
    }

    public static function endpoints(): array
    {
        return [
            'show' => ['GET', '/api/fof/upgrade-advisor/report'],
            'refresh' => ['POST', '/api/fof/upgrade-advisor/report/refresh'],
            'export' => ['GET', '/api/fof/upgrade-advisor/report/export'],
        ];
    }

    #[Test]
    #[DataProvider('reportEndpoints')]
    public function without_a_target_the_report_says_there_is_nothing_to_check(string $method, string $path)
    {
        $response = $this->send($this->request($method, $path, ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody(), true)['data'];

        $this->assertSame('fof-upgrade-advisor-reports', $data['type']);
        $this->assertSame('latest', $data['attributes']['overall']);
        $this->assertNull($data['attributes']['target']);
        $this->assertSame([], $data['attributes']['checks']);
    }

    public static function reportEndpoints(): array
    {
        return [
            'show' => ['GET', '/api/fof/upgrade-advisor/report'],
            'refresh' => ['POST', '/api/fof/upgrade-advisor/report/refresh'],
        ];
    }

    #[Test]
    public function without_a_target_there_is_nothing_to_export()
    {
        $response = $this->send($this->request('GET', '/api/fof/upgrade-advisor/report/export', ['authenticatedAs' => 1]));

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function with_a_target_the_report_lists_each_check_for_the_admin_page()
    {
        $this->targeting('99.0.0');

        $response = $this->send($this->request('GET', '/api/fof/upgrade-advisor/report', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode());

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];

        $this->assertSame('fail', $attributes['overall']);
        $this->assertSame('3.0', $attributes['target']);
        $this->assertSame([[
            'id' => 'php-version',
            'category' => 'environment',
            'status' => 'fail',
            'current' => PHP_VERSION,
            'meta' => ['required' => '99.0.0'],
        ]], $attributes['checks']);
        $this->assertNotFalse(strtotime($attributes['checkedAt']));
        $this->assertNotFalse(strtotime($attributes['dataAsOf']));
    }

    #[Test]
    public function with_a_target_the_report_downloads_as_csv()
    {
        $this->targeting('8.0.0');

        $response = $this->send($this->request('GET', '/api/fof/upgrade-advisor/report/export', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('php-version', (string) $response->getBody());
    }

    #[Test]
    public function the_database_check_identifies_the_real_database_server()
    {
        $this->targeting('8.0.0', [DatabaseVersionCheck::class]);

        $response = $this->send($this->request('GET', '/api/fof/upgrade-advisor/report', ['authenticatedAs' => 1]));
        $check = json_decode((string) $response->getBody(), true)['data']['attributes']['checks'][0];

        // The test database may be MySQL or MariaDB; either way it must be named and versioned, not "unknown".
        $this->assertSame('pass', $check['status']);
        $this->assertContains($check['meta']['server'], ['MySQL', 'MariaDB']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $check['meta']['version']);
    }
}
