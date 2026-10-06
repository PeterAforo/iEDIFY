<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Services;

/**
 * Maps the About page's CMS section list onto designed slots: intro hero,
 * reflections story, concept, vision quote, mission + strategic perspective,
 * values, pillars, communities, partners and closing CTAs.
 */
final class AboutLayout
{
    use SectionMap;

    public const KEYS = [
        'about us' => 'intro',
        'our reflections' => 'reflections',
        'our concept of intervention' => 'concept',
        'our vision' => 'vision',
        'our mission' => 'mission',
        'what guides us' => 'values',
        'program pillars' => 'pillars',
        'our communities' => 'communities',
        'our partners' => 'partners',
        'meet our team' => 'team',
        'theory of change' => 'theory',
    ];

    /** @return array{slots: array<string, array>, leftover: list<array>} */
    public static function build(array $sections): array
    {
        $map = self::collect($sections, self::KEYS);
        $slots = $map['slots'];
        if (isset($slots['intro'])) {
            $slots['intro'] = self::intro($slots['intro']);
        }
        if (isset($slots['mission'])) {
            $slots['mission'] = self::mission($slots['mission']);
        }
        return ['slots' => $slots, 'leftover' => $map['leftover']];
    }

    /**
     * The intro chunk's first text block holds "Title\n\nlead": split it so
     * the title can be the page-level h1 and the rest the hero lead.
     */
    private static function intro(array $bag): array
    {
        $first = (string) ($bag['texts'][0] ?? '');
        $parts = preg_split('/\n{2,}/', $first, 2);
        $bag['title'] = trim((string) ($parts[0] ?? '')) ?: $bag['heading'];
        $bag['lead'] = isset($parts[1]) ? trim($parts[1]) : '';
        $bag['texts'] = array_slice($bag['texts'], 1);
        return $bag;
    }

    /**
     * The mission chunk's second text block may carry a "Label\n\nbody"
     * sub-section (the strategic perspective); expose it as a side note.
     */
    private static function mission(array $bag): array
    {
        foreach ($bag['texts'] as $i => $text) {
            $parts = preg_split('/\n{2,}/', (string) $text, 2);
            if (count($parts) === 2 && mb_strlen($parts[0]) <= 60 && !str_contains($parts[0], '.')) {
                $bag['note'] = ['title' => trim($parts[0]), 'text' => trim($parts[1])];
                array_splice($bag['texts'], $i, 1);
                break;
            }
        }
        return $bag;
    }
}
