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

use FoF\UpgradeAdvisor\Check\CheckResult;
use FoF\UpgradeAdvisor\CsvReport;
use FoF\UpgradeAdvisor\Report;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class CsvReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    protected function csv(): CsvReport
    {
        // Echo the key and params back so assertions can see exactly what was asked for.
        $translator = Mockery::mock(TranslatorInterface::class);
        $translator->shouldReceive('trans')->andReturnUsing(function (string $key, array $params = []) {
            return $params ? $key.' '.json_encode($params) : $key;
        });

        return new CsvReport($translator);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function extension(string $name, string $action, array $overrides = []): array
    {
        return array_merge([
            'id' => str_replace('/', '-', $name),
            'name' => $name,
            'title' => ucfirst(explode('/', $name)[1]),
            'installedVersion' => '1.0.0',
            'status' => 'compatible',
            'reason' => null,
            'replacement' => null,
            'replacementCompatible' => null,
            'compatibleVersion' => null,
            'latestVersion' => null,
            'source' => 'packagist',
            'contact' => ['forum' => null, 'issues' => null, 'source' => null, 'authors' => []],
            'action' => $action,
            'hint' => ['key' => $action, 'params' => []],
        ], $overrides);
    }

    /**
     * @param array<int, array<string, mixed>> $extensions
     */
    protected function report(array $extensions): Report
    {
        return new Report([
            ['id' => 'php-version', 'category' => 'environment', 'result' => CheckResult::pass('8.3.1')],
            ['id' => 'database-version', 'category' => 'database', 'result' => CheckResult::warning('MariaDB 10.11', ['recommended' => '11.8.0', 'warningType' => 'below_recommended'])],
            ['id' => 'extension-compatibility', 'category' => 'extensions', 'result' => CheckResult::fail('1 / 3', ['extensions' => $extensions])],
        ], CheckResult::FAIL, '3.0', 1790000000, 1789990000);
    }

    #[Test]
    #[DataProvider('filenames')]
    public function names_the_file_after_the_community(string $title, string $expected)
    {
        // 2026-09-30 12:00 UTC
        $this->assertSame($expected, CsvReport::filename($title, 1790769600));
    }

    public static function filenames(): array
    {
        return [
            'plain title' => ['IanM Flarum Dev (English)', 'ianm-flarum-dev-english-report-2026-09-30.csv'],
            'accents transliterated' => ['Forum Français', 'forum-francais-report-2026-09-30.csv'],
            'nothing sluggable' => ['!!!', 'flarum-report-2026-09-30.csv'],
            'empty' => ['', 'flarum-report-2026-09-30.csv'],
        ];
    }

    #[Test]
    #[DataProvider('cells')]
    public function neutralises_cells_a_spreadsheet_would_run_as_a_formula(string $value, string $expected)
    {
        $this->assertSame($expected, CsvReport::cell($value));
    }

    public static function cells(): array
    {
        return [
            'equals' => ['=HYPERLINK("http://evil")', '\'=HYPERLINK("http://evil")'],
            'plus' => ['+1', "'+1"],
            'minus' => ['-1', "'-1"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\t=1", "'\t=1"],
            'carriage return' => ["\r=1", "'\r=1"],
            'ordinary text' => ['Approval', 'Approval'],
            'empty' => ['', ''],
        ];
    }

    #[Test]
    public function lists_environment_checks_first_then_extensions_in_the_admin_page_order()
    {
        $rows = $this->csv()->rows($this->report([
            $this->extension('acme/zebra', 'none'),
            $this->extension('acme/private', 'unknown'),
            $this->extension('acme/apple', 'none'),
            $this->extension('fof/nightmode', 'remove'),
        ]), 'https://example.com/admin');

        $this->assertSame(
            ['php-version', 'database-version', 'acme-private', 'fof-nightmode', 'acme-apple', 'acme-zebra'],
            array_column($rows, 'Extension ID')
        );
        $this->assertSame(['environment', 'database', 'extension', 'extension', 'extension', 'extension'], array_column($rows, 'Type'));
    }

    #[Test]
    public function an_extension_row_carries_everything_needed_to_raise_a_ticket()
    {
        $rows = $this->csv()->rows($this->report([
            $this->extension('blomstra/turnstile', 'switch_replacement', [
                'title' => 'Turnstile',
                'installedVersion' => '0.1.6',
                'status' => 'abandoned',
                'replacement' => 'blazite/flarum-turnstile',
                'replacementCompatible' => true,
                'source' => 'core',
                'hint' => ['key' => 'switch_replacement', 'params' => ['replacement' => 'blazite/flarum-turnstile']],
                'contact' => [
                    'forum' => 'https://discuss.flarum.org/d/1',
                    'issues' => 'https://github.com/blomstra/turnstile/issues',
                    'source' => null,
                    'authors' => [
                        ['name' => 'Jane', 'email' => 'jane@example.com', 'homepage' => null],
                        ['name' => null, 'email' => null, 'homepage' => 'https://example.org'],
                    ],
                ],
            ]),
        ]), 'https://example.com/admin');

        $this->assertSame([
            'Type' => 'extension',
            'Title' => 'Turnstile',
            'Package' => 'blomstra/turnstile',
            'Extension ID' => 'blomstra-turnstile',
            'Installed version' => '0.1.6',
            'Status' => 'abandoned',
            'Action group' => 'switch_replacement',
            'Recommended action' => 'fof-upgrade-advisor.admin.hints.switch_replacement {"replacement":"blazite\/flarum-turnstile"}',
            'Replacement' => 'blazite/flarum-turnstile',
            'Replacement ready' => 'yes',
            'Compatible version' => '',
            'Latest version' => '',
            'Data source' => 'core',
            'Admin page' => 'https://example.com/admin#/extension/blomstra-turnstile',
            'Support thread' => 'https://discuss.flarum.org/d/1',
            'Issues' => 'https://github.com/blomstra/turnstile/issues',
            'Source' => '',
            'Authors' => 'Jane <jane@example.com>; https://example.org',
            'Checked at' => '2026-09-21T14:13:20+00:00',
            'Remote data as of' => '2026-09-21T11:26:40+00:00',
            'Readiness' => 'to_do',
            'Target' => '3.0',
        ], $rows[2]);
    }

    #[Test]
    public function an_environment_row_describes_the_check_like_the_admin_page_does()
    {
        $row = $this->csv()->rows($this->report([]), 'https://example.com/admin')[1];

        $this->assertSame('fof-upgrade-advisor.admin.checks.database-version.title', $row['Title']);
        $this->assertSame('MariaDB 10.11', $row['Installed version']);
        $this->assertSame('warning', $row['Status']);
        $this->assertSame('review', $row['Action group']);
        $this->assertSame('ready', $row['Readiness'], 'a recommendation is not a blocker');
        $this->assertSame(
            'fof-upgrade-advisor.admin.checks.database-version.warning_below_recommended {"current":"MariaDB 10.11","required":"","recommended":"11.8.0","target":"3.0"}',
            $row['Recommended action']
        );
        $this->assertSame('', $row['Admin page']);
    }

    #[Test]
    public function renders_excel_friendly_csv_with_a_header_row()
    {
        $csv = $this->csv();
        $output = $csv->render($csv->rows($this->report([
            $this->extension('acme/evil', 'none', ['title' => '=cmd|calc']),
        ]), 'https://example.com/admin'));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $output, 'UTF-8 BOM so Excel detects the encoding');

        $lines = array_map('str_getcsv', explode("\n", trim(substr($output, 3))));

        $this->assertSame(CsvReport::COLUMNS, $lines[0]);
        $this->assertCount(4, $lines);
        $this->assertSame("'=cmd|calc", $lines[3][1]);
    }

    #[Test]
    public function a_failing_environment_check_is_blocked()
    {
        $report = new Report([
            ['id' => 'php-version', 'category' => 'environment', 'result' => CheckResult::fail('8.1.0', ['required' => '8.3.0'])],
        ], CheckResult::FAIL, '3.0', 1790000000, 1789990000);

        $row = $this->csv()->rows($report, 'https://example.com/admin')[0];

        $this->assertSame('fix', $row['Action group']);
        $this->assertSame('blocked', $row['Readiness']);
    }
}
