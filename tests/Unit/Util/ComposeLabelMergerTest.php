<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\ComposeLabelMerger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;

final class ComposeLabelMergerTest extends TestCase
{
    public function testReturnsBaseWhenOverrideIsNull(): void
    {
        $base = ['traefik.enable=true'];

        $this->assertSame($base, ComposeLabelMerger::merge($base, null));
    }

    public function testReturnsBaseWhenOverrideIsEmptyArray(): void
    {
        $base = ['traefik.enable=true'];

        $this->assertSame($base, ComposeLabelMerger::merge($base, []));
    }

    public function testAppendsListFormOverrideToListFormBase(): void
    {
        $base = ['traefik.enable=true', 'traefik.http.routers.web.rule=Host(`app.test`)'];
        $override = ['traefik.http.routers.preview.rule=Host(`preview.app.test`)'];

        $this->assertSame(
            [
                'traefik.enable=true',
                'traefik.http.routers.web.rule=Host(`app.test`)',
                'traefik.http.routers.preview.rule=Host(`preview.app.test`)',
            ],
            ComposeLabelMerger::merge($base, $override),
        );
    }

    public function testMergesMapFormByKeyWithOverrideWinning(): void
    {
        $base = [
            'traefik.enable' => 'true',
            'traefik.http.routers.web.rule' => 'Host(`app.test`)',
        ];
        $override = [
            'traefik.http.routers.web.rule' => 'Host(`new.test`)',
            'traefik.http.routers.api.rule' => 'Host(`api.test`)',
        ];

        $this->assertSame(
            [
                'traefik.enable' => 'true',
                'traefik.http.routers.web.rule' => 'Host(`new.test`)',
                'traefik.http.routers.api.rule' => 'Host(`api.test`)',
            ],
            ComposeLabelMerger::merge($base, $override),
        );
    }

    public function testOverrideTagReplacesBaseEntirely(): void
    {
        $base = ['traefik.enable=true', 'traefik.http.routers.web.rule=Host(`app.test`)'];
        $override = new TaggedValue('override', ['traefik.http.routers.preview.rule=Host(`preview.test`)']);

        $this->assertSame(
            ['traefik.http.routers.preview.rule=Host(`preview.test`)'],
            ComposeLabelMerger::merge($base, $override),
        );
    }

    public function testResetTagWithEmptyListDropsAllBaseLabels(): void
    {
        $base = ['traefik.enable=true', 'traefik.http.routers.web.rule=Host(`app.test`)'];
        $override = new TaggedValue('reset', []);

        $this->assertSame([], ComposeLabelMerger::merge($base, $override));
    }

    public function testCoercesMixedFormsToListBeforeAppending(): void
    {
        $base = ['traefik.enable=true'];
        $override = [
            'traefik.http.routers.web.rule' => 'Host(`app.test`)',
        ];

        $this->assertSame(
            [
                'traefik.enable=true',
                'traefik.http.routers.web.rule=Host(`app.test`)',
            ],
            ComposeLabelMerger::merge($base, $override),
        );
    }

    public function testUnknownTagFallsThroughToStandardMerge(): void
    {
        $base = ['traefik.enable=true'];
        $override = new TaggedValue('weird-future-tag', ['traefik.http.routers.web.rule=Host(`app.test`)']);

        $this->assertSame(
            ['traefik.enable=true', 'traefik.http.routers.web.rule=Host(`app.test`)'],
            ComposeLabelMerger::merge($base, $override),
        );
    }
}
