<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Services;

/**
 * Shared section-to-slot mapping for designed pages (home, about).
 *
 * Sections are grouped into chunks by their heading block; known headings
 * feed bespoke layout slots, everything else is returned as leftover blocks
 * for the generic section renderer, so editor content is never lost.
 */
trait SectionMap
{
    /** @return array{slots: array<string, array>, leftover: list<array>} */
    private static function collect(array $sections, array $keys): array
    {
        $slots = [];
        $leftover = [];
        foreach (self::chunks($sections) as [$heading, $blocks]) {
            $key = $heading === null ? null : ($keys[self::normalise($heading)] ?? null);
            if ($key === null || isset($slots[$key])) {
                if ($heading !== null) {
                    $leftover[] = ['type' => 'heading', 'text' => $heading];
                }
                array_push($leftover, ...$blocks);
                continue;
            }
            $slots[$key] = self::bag($heading, $blocks);
        }
        return ['slots' => $slots, 'leftover' => $leftover];
    }

    /** @return list<array{0: ?string, 1: list<array>}> */
    private static function chunks(array $sections): array
    {
        $chunks = [];
        $heading = null;
        $blocks = [];
        foreach ($sections as $block) {
            if (($block['type'] ?? null) === 'heading') {
                if ($heading !== null || $blocks !== []) {
                    $chunks[] = [$heading, $blocks];
                }
                $heading = (string) ($block['text'] ?? '');
                $blocks = [];
                continue;
            }
            $blocks[] = $block;
        }
        if ($heading !== null || $blocks !== []) {
            $chunks[] = [$heading, $blocks];
        }
        return $chunks;
    }

    private static function normalise(string $heading): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $heading)));
    }

    private static function bag(string $heading, array $blocks): array
    {
        $bag = ['heading' => $heading, 'texts' => [], 'rich' => [], 'ctas' => [], 'cards' => [], 'gallery' => [], 'images' => [], 'stats' => [], 'quotes' => [], 'other' => []];
        foreach ($blocks as $block) {
            match ($block['type'] ?? null) {
                'text' => $bag['texts'][] = (string) $block['text'],
                'rich_text' => $bag['rich'][] = (string) $block['html'],
                'cta' => $bag['ctas'][] = $block,
                'cards', 'timeline' => $bag['cards'][] = $block['items'] ?? [],
                'gallery' => array_push($bag['gallery'], ...($block['items'] ?? [])),
                'image' => $bag['images'][] = $block,
                'statistic' => $bag['stats'][] = $block,
                'quote' => $bag['quotes'][] = $block,
                default => $bag['other'][] = $block,
            };
        }
        return $bag;
    }
}
