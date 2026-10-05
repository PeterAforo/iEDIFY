<?php

declare(strict_types=1);

namespace IEdify\Tests\Unit;

use IEdify\Modules\CMS\Services\Sections;
use PHPUnit\Framework\TestCase;

final class SectionValidationTest extends TestCase
{
    public function testRichTextRemovesScriptHandlersAndUnsafeLinks(): void
    {
        $sections = (new Sections())->validate([['type' => 'rich_text', 'html' => '<p onclick="alert(1)">Hello<script>alert(2)</script><a href="javascript:alert(3)">Link</a></p>']]);
        self::assertStringNotContainsString('<script', $sections[0]['html']);
        self::assertStringNotContainsString('onclick', $sections[0]['html']);
        self::assertStringNotContainsString('javascript:', $sections[0]['html']);
        self::assertStringContainsString('Hello', $sections[0]['html']);
    }

    public function testUnknownExecutableBlockTypesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Sections())->validate([['type' => 'php', 'code' => 'echo 1;']]);
    }

    public function testUnsafeCallToActionUrlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Sections())->validate([['type' => 'cta', 'label' => 'Go', 'url' => 'javascript:alert(1)']]);
    }

    public function testTargetsCannotBeSubmittedAsUnclassifiedStatistics(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Sections())->validate([['type' => 'statistic', 'label' => 'Youth trained', 'value' => '5000']]);
    }
}
