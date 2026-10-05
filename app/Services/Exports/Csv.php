<?php

declare(strict_types=1);

namespace IEdify\Services\Exports;

final class Csv
{
    public static function safeCell(string|int|float|null $value): string
    {
        $text = (string) $value;
        return preg_match('/^(?:[\x00-\x20\p{Z}\x{FEFF}]*[=+\-@]|[\t\r\n])/u', $text) === 1 ? "'" . $text : $text;
    }

    public static function write($stream, array $columns, iterable $rows): void
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('CSV output requires an open stream.');
        }
        fputcsv($stream, array_map(self::safeCell(...), $columns), ',', '"', '', "\r\n");
        foreach ($rows as $row) {
            $values = array_map(static fn (string $column): string => self::safeCell($row[$column] ?? null), $columns);
            if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
                throw new \RuntimeException('CSV output could not be written.');
            }
        }
    }
}
