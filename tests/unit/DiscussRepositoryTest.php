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

use FoF\UpgradeAdvisor\Repository\DiscussRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DiscussRepositoryTest extends TestCase
{
    protected function discussionId(?string $url): ?string
    {
        $repo = (new \ReflectionClass(DiscussRepository::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(DiscussRepository::class, 'discussionId');
        $m->setAccessible(true);

        return $m->invoke($repo, $url);
    }

    /**
     * @dataProvider urls
     * @test
     */
    public function extracts_the_discussion_id_from_supported_urls(?string $url, ?string $expected)
    {
        $this->assertSame($expected, $this->discussionId($url));
    }

    public static function urls(): array
    {
        return [
            'bare id' => ['https://discuss.flarum.org/d/39374', '39374'],
            'slugged id' => ['https://discuss.flarum.org/d/23219-progressive-web-app', '23219'],
            'id with trailing path' => ['https://discuss.flarum.org/d/39374/2', '39374'],
            'http scheme' => ['http://discuss.flarum.org/d/10395', '10395'],

            'non-discuss host' => ['https://github.com/foo/bar', null],
            'discuss but not a thread' => ['https://discuss.flarum.org/u/ianm', null],
            'empty string' => ['', null],
            'null' => [null, null],
            'garbage' => ['not a url', null],
        ];
    }
}
