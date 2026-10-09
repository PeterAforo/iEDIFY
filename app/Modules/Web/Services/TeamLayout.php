<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Services;

/**
 * Maps the Team page's CMS section list onto designed slots: hero intro
 * with stats, the board group's intro copy and the closing join CTA.
 * Roster members themselves come from the team_members table, grouped
 * by roster_group / youth_adviser.
 */
final class TeamLayout
{
    use SectionMap;

    public const KEYS = [
        'our team' => 'intro',
        'governance & oversight' => 'board',
        'initiator and lead strategist' => 'lead',
        'want to join our team?' => 'cta',
    ];

    /** @return array{slots: array<string, array>, leftover: list<array>} */
    public static function build(array $sections): array
    {
        $map = self::collect($sections, self::KEYS);
        $slots = $map['slots'];
        foreach (['intro', 'board'] as $key) {
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
