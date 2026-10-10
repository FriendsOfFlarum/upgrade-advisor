<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor;

use FoF\UpgradeAdvisor\Check\CheckResult;
use Illuminate\Support\Str;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Flattens a {@see Report} into one CSV sheet for offline planning (ticketing,
 * estimation, budgeting).
 *
 * Headers and the "Action group" keys are fixed English so spreadsheet imports
 * and templates keep working across translations and releases; only the
 * human-readable text is translated.
 */
class CsvReport
{
    public const COLUMNS = [
        'Type',
        'Title',
        'Package',
        'Extension ID',
        'Installed version',
        'Status',
        'Action group',
        'Recommended action',
        'Replacement',
        'Replacement ready',
        'Compatible version',
        'Latest version',
        'Data source',
        'Admin page',
        'Support thread',
        'Issues',
        'Source',
        'Authors',
        'Checked at',
        'Remote data as of',
        // Appended, not inserted, so existing imports keyed by position still line up.
        'Readiness',
        // The Flarum version the row was checked against, e.g. "3.0".
        'Target',
    ];

    /**
     * Action groups for non-extension checks, keyed by check status.
     */
    protected const CHECK_ACTIONS = [
        CheckResult::PASS => ExtensionAction::NONE,
        CheckResult::WARNING => 'review',
        CheckResult::FAIL => 'fix',
    ];

    public function __construct(protected TranslatorInterface $translator)
    {
    }

    public static function filename(string $forumTitle, int $now): string
    {
        $slug = Str::slug($forumTitle);

        return ($slug !== '' ? $slug : 'flarum').'-report-'.gmdate('Y-m-d', $now).'.csv';
    }

    /**
     * Prefix values a spreadsheet would evaluate as a formula. Titles and author
     * names come from third-party composer.json files, so they are untrusted.
     */
    public static function cell(string $value): string
    {
        return $value !== '' && strpos("=+-@\t\r", $value[0]) !== false ? "'".$value : $value;
    }

    /**
     * @return array<int, array<string, string>> One associative row per item, keyed by column.
     */
    public function rows(Report $report, string $adminUrl): array
    {
        $common = [
            'Checked at' => gmdate('c', $report->checkedAt),
            'Remote data as of' => gmdate('c', $report->dataAsOf),
            'Target' => (string) $report->target,
        ];

        $rows = [];
        $extensions = [];

        foreach ($report->checks as $check) {
            if (isset($check['result']->meta['extensions']) && is_array($check['result']->meta['extensions'])) {
                $extensions = array_merge($extensions, $check['result']->meta['extensions']);
                continue;
            }

            $rows[] = $this->checkRow($check['id'], $check['category'], $check['result'], (string) $report->target) + $common;
        }

        foreach ($this->sortByUrgency($extensions) as $extension) {
            $rows[] = $this->extensionRow($extension, $adminUrl) + $common;
        }

        return array_map(function (array $row) {
            // Fill and order every column, so each row lines up with the header.
            return array_merge(array_fill_keys(self::COLUMNS, ''), $row);
        }, $rows);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    public function render(array $rows): string
    {
        $lines = [$this->line(self::COLUMNS)];

        foreach ($rows as $row) {
            $lines[] = $this->line(array_map([self::class, 'cell'], array_values($row)));
        }

        // BOM so Excel reads the file as UTF-8 rather than the system codepage.
        return "\xEF\xBB\xBF".implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, string>
     */
    protected function checkRow(string $id, string $category, CheckResult $result, string $target): array
    {
        $key = "fof-upgrade-advisor.admin.checks.$id";

        // Same key convention as the admin page's description.
        $descriptionKey = $result->status === CheckResult::WARNING && isset($result->meta['warningType'])
            ? "$key.warning_{$result->meta['warningType']}"
            : "$key.{$result->status}";

        return [
            'Type' => $category,
            'Title' => $this->translator->trans("$key.title"),
            'Extension ID' => $id,
            'Installed version' => (string) $result->current,
            'Status' => $result->status,
            'Action group' => self::CHECK_ACTIONS[$result->status] ?? $result->status,
            'Recommended action' => $this->translator->trans($descriptionKey, [
                'current' => (string) $result->current,
                'required' => (string) ($result->meta['required'] ?? ''),
                'recommended' => (string) ($result->meta['recommended'] ?? ''),
                'target' => $target,
            ]),
            // A warning is a recommendation, not a requirement; only a failure blocks.
            'Readiness' => $result->status === CheckResult::FAIL ? 'blocked' : 'ready',
        ];
    }

    /**
     * @param array<string, mixed> $ext
     *
     * @return array<string, string>
     */
    protected function extensionRow(array $ext, string $adminUrl): array
    {
        $contact = $ext['contact'];

        $authors = array_map(function (array $author) {
            $name = $author['name'] ?? null;
            $reach = ($author['email'] ?? null) ? '<'.$author['email'].'>' : ($author['homepage'] ?? '');

            return trim($name ? "$name $reach" : $reach);
        }, $contact['authors']);

        return [
            'Type' => 'extension',
            'Title' => (string) $ext['title'],
            'Package' => (string) $ext['name'],
            'Extension ID' => (string) $ext['id'],
            'Installed version' => (string) $ext['installedVersion'],
            'Status' => (string) $ext['status'],
            'Action group' => (string) $ext['action'],
            'Recommended action' => $this->translator->trans('fof-upgrade-advisor.admin.hints.'.$ext['hint']['key'], $ext['hint']['params']),
            'Replacement' => (string) $ext['replacement'],
            'Replacement ready' => $ext['replacementCompatible'] === null ? '' : ($ext['replacementCompatible'] ? 'yes' : 'no'),
            'Compatible version' => (string) $ext['compatibleVersion'],
            'Latest version' => (string) $ext['latestVersion'],
            'Data source' => (string) $ext['source'],
            'Admin page' => $adminUrl.'#/extension/'.$ext['id'],
            'Support thread' => (string) $contact['forum'],
            'Issues' => (string) $contact['issues'],
            'Source' => (string) $contact['source'],
            'Authors' => implode('; ', $authors),
            'Readiness' => ExtensionAction::readiness((string) $ext['action']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $extensions
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sortByUrgency(array $extensions): array
    {
        $rank = array_flip(ExtensionAction::ALL);

        usort($extensions, function (array $a, array $b) use ($rank) {
            $byAction = ($rank[$a['action']] ?? 99) <=> ($rank[$b['action']] ?? 99);

            return $byAction !== 0 ? $byAction : strcasecmp($a['title'], $b['title']);
        });

        return $extensions;
    }

    /**
     * RFC 4180 quoting. Written by hand because fputcsv's backslash escape
     * can't be disabled before PHP 7.4.
     *
     * @param string[] $fields
     */
    protected function line(array $fields): string
    {
        return implode(',', array_map(function (string $field) {
            return preg_match('/[",\r\n]/', $field) ? '"'.str_replace('"', '""', $field).'"' : $field;
        }, $fields));
    }
}
