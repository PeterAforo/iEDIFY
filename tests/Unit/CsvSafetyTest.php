<?php

declare(strict_types=1);

namespace IEdify\Tests\Unit;

use IEdify\Services\Exports\Csv;
use PHPUnit\Framework\TestCase;

final class CsvSafetyTest extends TestCase
{
    public function testSpreadsheetFormulaPrefixesAreEscapedIncludingLeadingWhitespace(): void
    {
        foreach (['=1+1', '+SUM(A1)', '-1+2', '@SUM(A1)', "\t=1+1", "\r=1+1", '   =1+1'] as $value) {
            self::assertStringStartsWith("'", Csv::safeCell($value));
        }
        self::assertSame('Normal text', Csv::safeCell('Normal text'));
        self::assertSame('42', Csv::safeCell(42));
    }
}
