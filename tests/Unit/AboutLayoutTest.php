<?php

declare(strict_types=1);

namespace IEdify\Tests\Unit;

use IEdify\Modules\Web\Services\AboutLayout;
use PHPUnit\Framework\TestCase;

final class AboutLayoutTest extends TestCase
{
    public function testKnownHeadingsMapToSlotsCaseInsensitively(): void
    {
        $layout = AboutLayout::build([
            ['type' => 'heading', 'text' => 'About Us'],
            ['type' => 'text', 'text' => "Championing Africa's Youth Potential\n\nWe believe in young founders."],
            ['type' => 'statistic', 'kind' => 'context', 'label' => 'People in Africa', 'value' => '1.54B', 'source' => 'x', 'period' => 'y'],
            ['type' => 'heading', 'text' => 'OUR  VISION'],
            ['type' => 'quote', 'text' => 'A thriving Africa', 'attribution' => 'iEDIFY Africa Vision'],
            ['type' => 'heading', 'text' => 'Meet Our Team'],
            ['type' => 'cta', 'label' => 'Meet our team', 'url' => '/team'],
        ]);

        self::assertSame("Championing Africa's Youth Potential", $layout['slots']['intro']['title']);
        self::assertSame('We believe in young founders.', $layout['slots']['intro']['lead']);
        self::assertSame('1.54B', $layout['slots']['intro']['stats'][0]['value']);
        self::assertSame('A thriving Africa', $layout['slots']['vision']['quotes'][0]['text']);
        self::assertSame('/team', $layout['slots']['team']['ctas'][0]['url']);
        self::assertSame([], $layout['leftover']);
    }

    public function testMissionExtractsStrategicPerspectiveSideNote(): void
    {
        $layout = AboutLayout::build([
            ['type' => 'heading', 'text' => 'Our Mission'],
            ['type' => 'text', 'text' => 'We equip youth entrepreneurs.'],
            ['type' => 'text', 'text' => "Strategic Perspective\n\nA long-horizon view of enterprise."],
        ]);

        $mission = $layout['slots']['mission'];
        self::assertSame(['We equip youth entrepreneurs.'], $mission['texts']);
        self::assertSame('Strategic Perspective', $mission['note']['title']);
        self::assertSame('A long-horizon view of enterprise.', $mission['note']['text']);
    }

    public function testCardCollectionsAndUnknownHeadingsFallBackSafely(): void
    {
        $layout = AboutLayout::build([
            ['type' => 'heading', 'text' => 'Our Communities'],
            ['type' => 'cards', 'items' => [['title' => 'Volunteers', 'text' => 'Give time'], ['title' => 'Alumni', 'text' => 'Stay close']]],
            ['type' => 'heading', 'text' => 'Brand New Section'],
            ['type' => 'text', 'text' => 'Editor addition'],
        ]);

        self::assertCount(2, $layout['slots']['communities']['cards'][0]);
        self::assertSame([
            ['type' => 'heading', 'text' => 'Brand New Section'],
            ['type' => 'text', 'text' => 'Editor addition'],
        ], $layout['leftover']);
    }

    public function testIntroWithoutLeadKeepsHeadingTitle(): void
    {
        $layout = AboutLayout::build([
            ['type' => 'heading', 'text' => 'About Us'],
            ['type' => 'text', 'text' => 'Single paragraph only'],
        ]);

        self::assertSame('Single paragraph only', $layout['slots']['intro']['title']);
        self::assertSame('', $layout['slots']['intro']['lead']);
    }
}
