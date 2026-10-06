<?php

declare(strict_types=1);

namespace IEdify\Tests\Unit;

use IEdify\Modules\Web\Services\HomeLayout;
use PHPUnit\Framework\TestCase;

final class HomeLayoutTest extends TestCase
{
    public function testKnownHeadingsMapToSlotsCaseInsensitively(): void
    {
        $layout = HomeLayout::build([
            ['type' => 'heading', 'text' => 'At a  Glance'],
            ['type' => 'statistic', 'kind' => 'context', 'label' => 'People', 'value' => '1.54B', 'source' => 'x', 'period' => 'y'],
            ['type' => 'heading', 'text' => 'OUR VISION'],
            ['type' => 'quote', 'text' => 'A thriving Africa', 'attribution' => ''],
            ['type' => 'heading', 'text' => 'Join the Movement'],
            ['type' => 'text', 'text' => 'Join us'],
            ['type' => 'cta', 'label' => 'Contact', 'url' => '/contact'],
            ['type' => 'rich_text', 'html' => '<p>Email</p>'],
        ]);

        self::assertSame('1.54B', $layout['slots']['glance']['stats'][0]['value']);
        self::assertSame('A thriving Africa', $layout['slots']['vision']['quotes'][0]['text']);
        self::assertSame(['Join us'], $layout['slots']['join']['texts']);
        self::assertSame('/contact', $layout['slots']['join']['ctas'][0]['url']);
        self::assertSame(['<p>Email</p>'], $layout['slots']['join']['rich']);
        self::assertSame([], $layout['leftover']);
    }

    public function testPillarCardsPairWithGalleryAndSplitNumbers(): void
    {
        $layout = HomeLayout::build([
            ['type' => 'heading', 'text' => 'Program Pillars'],
            ['type' => 'gallery', 'items' => [['media_id' => 4, 'alt' => 'A'], ['media_id' => 5, 'alt' => 'B']]],
            ['type' => 'cards', 'items' => [['title' => '01 - Advocacy', 'text' => 'One'], ['title' => 'Trust Fund', 'text' => 'Two']]],
            ['type' => 'cards', 'items' => [['title' => 'Pan-African Reach', 'text' => 'Value']]],
        ]);

        $pillars = $layout['slots']['pillars'];
        self::assertSame(['01', 'Advocacy', 4], [$pillars['items'][0]['number'], $pillars['items'][0]['title'], $pillars['items'][0]['media']['media_id']]);
        self::assertSame(['02', 'Trust Fund', 5], [$pillars['items'][1]['number'], $pillars['items'][1]['title'], $pillars['items'][1]['media']['media_id']]);
        self::assertSame('Pan-African Reach', $pillars['values'][0]['title']);
    }

    public function testUnknownAndDuplicateChunksFallBackToLeftoverWithHeadings(): void
    {
        $layout = HomeLayout::build([
            ['type' => 'text', 'text' => 'Intro before any heading'],
            ['type' => 'heading', 'text' => 'Our Mission'],
            ['type' => 'text', 'text' => 'First'],
            ['type' => 'heading', 'text' => 'Something New'],
            ['type' => 'text', 'text' => 'Editor addition'],
            ['type' => 'heading', 'text' => 'Our Mission'],
            ['type' => 'text', 'text' => 'Duplicate'],
        ]);

        self::assertSame(['First'], $layout['slots']['mission']['texts']);
        self::assertSame([
            ['type' => 'text', 'text' => 'Intro before any heading'],
            ['type' => 'heading', 'text' => 'Something New'],
            ['type' => 'text', 'text' => 'Editor addition'],
            ['type' => 'heading', 'text' => 'Our Mission'],
            ['type' => 'text', 'text' => 'Duplicate'],
        ], $layout['leftover']);
    }
}
