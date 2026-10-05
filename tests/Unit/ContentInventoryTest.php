<?php

declare(strict_types=1);

namespace IEdify\Tests\Unit;

use IEdify\Modules\CMS\Services\ContentInventory;
use PHPUnit\Framework\TestCase;

final class ContentInventoryTest extends TestCase
{
    public function testSourceCountsAndMissingHeroReferencesAreReconciled(): void
    {
        $report = (new ContentInventory())->inspect(dirname(__DIR__, 2) . '/iEDIFY_Website_Content_Pack');
        self::assertSame(14, $report['page_count']);
        self::assertSame(15, $report['team_count']);
        self::assertSame(24, $report['local_asset_count']);
        self::assertSame(26, $report['asset_reference_count']);
        self::assertContains('/images/landing/hero-slide-innovation.webp', $report['missing_assets']);
        self::assertContains('/images/landing/hero-slide-collaboration.webp', $report['missing_assets']);
        self::assertCount(2, $report['missing_assets']);
        self::assertNotEmpty($report['logo_palette']);
    }

    public function testOptimizedImageVariantsMapToOriginalPath(): void
    {
        self::assertSame('/images/logo-white.png', ContentInventory::sourcePath('/_next/image?url=%2Fimages%2Flogo-white.png&w=256&q=75'));
        self::assertSame('/images/logo-white.png', ContentInventory::sourcePath('https://www.iedifyafrica.org/_next/image?url=%2Fimages%2Flogo-white.png&w=1080&q=75'));
    }
}
