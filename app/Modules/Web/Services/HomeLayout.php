<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Services;

/**
 * Maps the homepage's CMS section list onto the designed homepage slots.
 * Known headings (see KEYS) feed bespoke layouts; everything else is
 * returned as leftover blocks so editor content is never lost.
 */
final class HomeLayout
{
    use SectionMap;

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
        $map = self::collect($sections, self::KEYS);
        if (isset($map['slots']['pillars'])) {
            $map['slots']['pillars'] = self::pillars($map['slots']['pillars']);
        }
        return $map;
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
