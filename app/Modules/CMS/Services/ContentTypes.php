<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Services;

/**
 * Friendly presentation of content types for the admin UI: labels,
 * which types editors may create, and starter blocks per type so a
 * "new" item never starts from an empty section list.
 */
final class ContentTypes
{
    public const LABELS = [
        'page' => 'Page',
        'legal' => 'Legal page',
        'team' => 'Team member',
        'program' => 'Program page',
        'pillar' => 'Pillar',
        'news' => 'News article',
        'story' => 'Story',
        'faq' => 'FAQ',
        'event' => 'Event',
        'opportunity' => 'Opportunity',
        'publication' => 'Publication',
        'hero' => 'Hero content',
        'auth_copy' => 'Auth copy',
    ];

    /** Types offered on the "Add new" screen. 'team' is excluded — members are created through the team manager so they get a roster row. 'auth_copy'/'hero' are system records. */
    public const CREATABLE = ['page', 'news', 'story', 'faq', 'event', 'opportunity', 'publication', 'program', 'pillar', 'legal'];

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? ucfirst($type);
    }

    /**
     * Starter sections for a newly created item, so the editor opens with
     * a sensible scaffold instead of an empty document.
     */
    public static function starterBlocks(string $type, string $title): array
    {
        return match ($type) {
            'faq' => [
                ['type' => 'text', 'text' => 'Answer the question here.'],
            ],
            'publication' => [
                ['type' => 'text', 'text' => 'Describe this publication.'],
            ],
            'team' => [
                ['type' => 'text', 'text' => "A short bio for {$title}."],
            ],
            'news', 'story', 'event', 'opportunity' => [
                ['type' => 'text', 'text' => 'Write the announcement here.'],
            ],
            'program', 'pillar' => [
                ['type' => 'heading', 'text' => $title],
                ['type' => 'text', 'text' => 'Describe the program.'],
            ],
            default => [
                ['type' => 'heading', 'text' => $title],
                ['type' => 'text', 'text' => 'Start writing here.'],
            ],
        };
    }

    /**
     * Slugs whose sections feed designed layout slots keyed by heading text.
     * Returns [heading => human description] for the editor's hint panel.
     */
    public static function slotHints(string $slug): array
    {
        return match ($slug) {
            '/' => [
                'At a glance' => 'statistics band under the hero',
                'Our vision' => 'vision statement',
                'Our mission' => 'mission statement',
                'Program pillars' => 'numbered pillar cards with photos',
                'Building together' => 'community strip',
                'Our theory of change' => 'theory of change section',
                'Join the movement' => 'closing call-to-action',
            ],
            '/about' => [
                'About us' => 'intro hero',
                'Our reflections' => 'reflections story',
                'Our concept of intervention' => 'concept section',
                'Our vision' => 'vision quote',
                'Our mission' => 'mission text',
                'What guides us' => 'values cards',
                'Program pillars' => 'pillar cards',
                'Our communities' => 'communities section',
                'Our partners' => 'partners section',
                'Meet our team' => 'team feature',
                'Theory of change' => 'theory of change',
            ],
            '/team' => [
                'Our team' => 'page intro',
                'Governance & oversight' => 'board section intro',
                'Initiator and lead strategist' => 'lead strategist feature',
                'Want to join our team?' => 'closing call-to-action',
            ],
            '/programs' => [
                'Our programs' => 'intro',
                'Advocacy, awareness raising and sensitization' => 'pillar 1',
                'A sustainable youth innovation & entrepreneurship trust fund' => 'pillar 2',
                'Innovation & entrepreneurship in education' => 'pillar 3',
                'Seed funding for youth startups' => 'pillar 4',
                'Our operational framework' => 'framework section',
                'Who we work with' => 'stakeholders',
                'Partnership opportunities' => 'partners section',
            ],
            '/impact' => [
                'Our impact' => 'intro',
                'Real stories, real stakes' => 'story section',
                'The pragmatic shift' => 'shift section',
            ],
            '/community' => [
                'iEDIFY Africa community' => 'intro',
                'Inside the hub' => 'hub section',
                'How to join' => 'steps',
                'Join the conversation' => 'join section',
            ],
            '/publications' => ['Knowledge hub' => 'intro', 'Document library' => 'library intro'],
            '/gallery' => ['Gallery' => 'intro (later headings become albums)'],
            '/resources' => ['Resources' => 'intro'],
            '/news' => ['Latest news' => 'intro'],
            '/events' => ['Events' => 'intro'],
            '/contact' => ['Contact us' => 'intro'],
            default => [],
        };
    }

    /** Normalised heading set a designed slug recognises — used to badge blocks in the editor. */
    public static function slotHeadings(string $slug): array
    {
        return array_map(
            static fn (string $h): string => strtolower(trim((string) preg_replace('/\s+/', ' ', $h))),
            array_keys(self::slotHints($slug)),
        );
    }
}
