<?php

/*
 * This file is part of fof/upgrade-advisor.
 *
 *  Copyright (c) 2026 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\UpgradeAdvisor\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\UpgradeAdvisor\CacheGeneration;
use FoF\UpgradeAdvisor\Check\CheckRegistry;
use FoF\UpgradeAdvisor\CsvReport;
use FoF\UpgradeAdvisor\Report;
use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Downloads the current report as CSV. Uses the same cached lookups as the
 * admin page; the "Remote data as of" column says how fresh they are.
 *
 * The route deliberately has no ".csv" suffix: hosts and CDNs (Cloudflare by
 * default) cache by file extension, which would cache this admin-only export.
 */
class ExportReportCsvController implements RequestHandlerInterface
{
    public function __construct(protected CheckRegistry $registry, protected CacheGeneration $generation, protected CsvReport $csv, protected SettingsRepositoryInterface $settings, protected UrlGenerator $url)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $now = time();
        $report = Report::build($this->registry, $this->generation, $now);
        $filename = CsvReport::filename((string) $this->settings->get('forum_title'), $now);

        $response = new Response('php://memory', 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            // Flarum's stock .htaccess gives unlisted types (text/csv included) a
            // one-month expiry; mod_expires leaves responses that set their own alone.
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]);

        $response->getBody()->write($this->csv->render($this->csv->rows($report, $this->url->to('admin')->base())));

        return $response;
    }
}
