<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Services;

/**
 * Maps the editorial pages' CMS section lists onto designed slots.
 * Each slug declares which headings feed which slots; the first text
 * block of the intro chunk is split into title + lead (same convention
 * as About/Team). Unmapped headings fall through to leftover blocks.
 */
final class PageLayouts
{
    use SectionMap;

    private const KEYS = [
        '/programs' => [
            'our programs' => 'intro',
            'advocacy, awareness raising and sensitization' => 'pillar1',
            'a sustainable youth innovation & entrepreneurship trust fund' => 'pillar2',
            'innovation & entrepreneurship in education' => 'pillar3',
            'seed funding for youth startups' => 'pillar4',
            'our operational framework' => 'framework',
            'who we work with' => 'stakeholders',
            'partnership opportunities' => 'partners',
        ],
        '/impact' => [
            'our impact' => 'intro',
            'real stories, real stakes' => 'story',
            'the pragmatic shift' => 'shift',
        ],
        '/community' => [
            'iedify africa community' => 'intro',
            'inside the hub' => 'hub',
            'how to join' => 'steps',
            'join the conversation' => 'join',
        ],
        '/publications' => [
            'knowledge hub' => 'intro',
            'document library' => 'library',
        ],
        '/gallery' => [
            'gallery' => 'intro',
        ],
        '/resources' => [
            'resources' => 'intro',
        ],
        '/news' => [
            'latest news' => 'intro',
        ],
        '/events' => [
            'events' => 'intro',
        ],
        '/contact' => [
            'contact us' => 'intro',
        ],
    ];

    /** @return array{slots: array<string, array>, leftover: list<array>} */
    public static function build(string $slug, array $sections): array
    {
        $map = self::collect($sections, self::KEYS[$slug] ?? []);
        $slots = $map['slots'];
        foreach (['intro', 'library', 'shift', 'hub', 'steps', 'join'] as $key) {
            if (isset($slots[$key])) {
                $slots[$key] = self::splitLead($slots[$key]);
            }
        }
        return ['slots' => $slots, 'leftover' => $map['leftover']];
    }

    /**
     * The first text block of a chunk holds "Title\n\nlead": split it so
     * the title can headline its slot and the rest becomes the lead copy.
     */
    private static function splitLead(array $bag): array
    {
        $first = (string) ($bag['texts'][0] ?? '');
        $parts = preg_split('/\n{2,}/', $first, 2);
        $bag['title'] = trim((string) ($parts[0] ?? '')) ?: $bag['heading'];
        $bag['lead'] = isset($parts[1]) ? trim($parts[1]) : '';
        $bag['texts'] = array_slice($bag['texts'], 1);
        return $bag;
    }
}
