<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Services;

/**
 * Groups a gallery page's CMS sections into collage entries: each heading
 * chunk that contains a gallery collection becomes an album — the heading
 * is the album (event) title, an optional image block is the cover, a text
 * block the description, and gallery items the album's media. A chunk with
 * a quote but no gallery becomes a dark quote card interleaved in the grid.
 *
 * The first chunk is the page intro and is skipped. Chunks that are neither
 * albums nor quote cards are returned as extra blocks so editor content is
 * never lost. Entries preserve the section order the editor chose.
 */
final class GalleryAlbums
{
    use SectionMap;

    /**
     * @return array{
     *     entries: list<array>,
     *     extra: list<array>
     * }
     */
    public static function collect(array $sections): array
    {
        $entries = [];
        $extra = [];
        $usedSlugs = [];
        foreach (self::chunks($sections) as $index => [$heading, $blocks]) {
            if ($index === 0) {
                continue;
            }
            $items = [];
            $quotes = [];
            $cover = null;
            $description = '';
            foreach ($blocks as $block) {
                $type = $block['type'] ?? null;
                if ($type === 'gallery') {
                    array_push($items, ...($block['items'] ?? []));
                } elseif ($type === 'quote') {
                    $quotes[] = $block;
                } elseif ($type === 'image' && $cover === null) {
                    $cover = (int) ($block['media_id'] ?? 0);
                } elseif ($type === 'text' && $description === '') {
                    $description = (string) $block['text'];
                }
            }
            if ($items !== [] && $heading !== null) {
                $entries[] = [
                    'type' => 'album',
                    'slug' => self::slug($heading, $usedSlugs),
                    'title' => $heading,
                    'description' => $description,
                    'cover_id' => $cover ?? (int) ($items[0]['media_id'] ?? 0),
                    'items' => $items,
                ];
                continue;
            }
            if ($items === [] && $quotes !== []) {
                $entries[] = [
                    'type' => 'quote',
                    'label' => $heading ?? '',
                    'quote' => $quotes[0],
                ];
                continue;
            }
            if ($heading !== null) {
                $extra[] = ['type' => 'heading', 'text' => $heading];
            }
            array_push($extra, ...$blocks);
        }
        return ['entries' => $entries, 'extra' => $extra];
    }

    /** @param array<string, true> $used */
    private static function slug(string $title, array &$used): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
        $base = $base !== '' ? $base : 'album';
        $slug = $base;
        for ($i = 2; isset($used[$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        $used[$slug] = true;
        return $slug;
    }
}
