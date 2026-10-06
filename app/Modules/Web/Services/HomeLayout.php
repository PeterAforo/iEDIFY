<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Services;

/**
 * Maps the homepage's CMS section list onto the designed homepage slots.
 *
 * Sections are grouped into chunks by their heading block; known headings
 * (see KEYS) feed bespoke layouts, everything else is returned as leftover
 * blocks for the generic section renderer, so editor content is never lost.
 */
final class HomeLayout
{
    public const KEYS = [
        'at a glance' => 'glance',
        'our vision' => 'vision',
        'our mission' => 'mission',
        'program pillars' => 'pillars',
        'building together' => 'building',
        'our theory of change' => 'theory',
        'join the movement' => 'join',
    ];

    /** @return array{slots: array<string, array>, leftover: list<array>} */
    public static function build(array $sections): array
    {
        $slots = [];
        $leftover = [];
        foreach (self::chunks($sections) as [$heading, $blocks]) {
            $key = $heading === null ? null : (self::KEYS[self::normalise($heading)] ?? null);
            if ($key === null || isset($slots[$key])) {
                if ($heading !== null) {
                    $leftover[] = ['type' => 'heading', 'text' => $heading];
                }
                array_push($leftover, ...$blocks);
                continue;
            }
            $slots[$key] = self::bag($heading, $blocks);
        }
        if (isset($slots['pillars'])) {
            $slots['pillars'] = self::pillars($slots['pillars']);
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

    /**
     * The first card set holds the numbered pillars (paired with gallery
     * photos in order); a second card set holds the organisation's values.
     */
    private static function pillars(array $bag): array
    {
        $items = [];
        foreach ($bag['cards'][0] ?? [] as $i => $card) {
            $title = (string) ($card['title'] ?? '');
            $number = null;
            if (preg_match('/^\s*(\d{1,2})\s*[-–—.:]\s*(.+)$/u', $title, $m)) {
                $number = $m[1];
                $title = $m[2];
            }
            $items[] = [
                'number' => $number ?? str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'title' => $title,
                'text' => (string) ($card['text'] ?? ''),
                'media' => $bag['gallery'][$i] ?? null,
            ];
        }
        $bag['items'] = $items;
        $bag['values'] = $bag['cards'][1] ?? [];
        return $bag;
    }
}
