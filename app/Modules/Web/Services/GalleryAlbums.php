<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Services;

/**
 * Groups a gallery page's CMS sections into albums: each heading chunk that
 * contains a gallery collection becomes one album — the heading is the album
 * (event) title, an optional image block is the cover, a text block the
 * description, and gallery items the album's media.
 *
 * The first chunk is the page intro and is skipped. Chunks with no gallery
 * items are returned as extra blocks so editor content is never lost.
 */
final class GalleryAlbums
{
    use SectionMap;

    /**
     * @return array{
     *     albums: list<array{slug: string, title: string, description: string, cover_id: int, items: list<array{media_id: int, alt: string}>}>,
     *     extra: list<array>
     * }
     */
    public static function collect(array $sections): array
    {
        $albums = [];
        $extra = [];
        $usedSlugs = [];
        foreach (self::chunks($sections) as $index => [$heading, $blocks]) {
            if ($index === 0) {
                continue;
            }
            $items = [];
            $cover = null;
            $description = '';
            foreach ($blocks as $block) {
                $type = $block['type'] ?? null;
                if ($type === 'gallery') {
                    array_push($items, ...($block['items'] ?? []));
                } elseif ($type === 'image' && $cover === null) {
                    $cover = (int) ($block['media_id'] ?? 0);
                } elseif ($type === 'text' && $description === '') {
                    $description = (string) $block['text'];
                }
            }
            if ($items === [] || $heading === null) {
                if ($heading !== null) {
                    $extra[] = ['type' => 'heading', 'text' => $heading];
                }
                array_push($extra, ...$blocks);
                continue;
            }
            $albums[] = [
                'slug' => self::slug($heading, $usedSlugs),
                'title' => $heading,
                'description' => $description,
                'cover_id' => $cover ?? (int) ($items[0]['media_id'] ?? 0),
                'items' => $items,
            ];
        }
        return ['albums' => $albums, 'extra' => $extra];
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
