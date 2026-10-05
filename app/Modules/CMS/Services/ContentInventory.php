<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Services;

use RuntimeException;

final class ContentInventory
{
    public function inspect(string $source): array
    {
        $content = $this->json($source . '/website_content.json');
        $manifest = $this->json($source . '/asset_manifest.json');
        $mapped = [];
        $local = [];
        foreach ($manifest as $asset) {
            $relative = $asset['local_file'];
            if (!is_string($relative) || !preg_match('~^images/[a-zA-Z0-9_.-]+$~D', $relative) || str_contains($relative, '..')) {
                throw new RuntimeException('Unsafe content-pack asset path.');
            }
            $file = $source . '/' . $relative;
            if (!is_file($file) || ($size = getimagesize($file)) === false) {
                throw new RuntimeException('A mapped source asset is missing or invalid: ' . $relative);
            }
            $mapped[self::sourcePath($asset['source'])] = true;
            $local[$relative] = ['sha256' => hash_file('sha256', $file), 'width' => $size[0], 'height' => $size[1], 'mime' => $size['mime']];
        }
        $missing = [];
        foreach ($content['pages'] as $page) {
            foreach ($page['images'] ?? [] as $image) {
                $path = self::sourcePath($image['url']);
                if (!isset($mapped[$path])) {
                    $missing[$path] = true;
                }
            }
        }
        return [
            'captured_date' => $content['captured_date'],
            'page_count' => count($content['pages']),
            'team_count' => count($content['expanded_team_profiles']),
            'local_asset_count' => count($local),
            'asset_reference_count' => count($manifest),
            'routes' => array_map(static fn (array $page): string => parse_url($page['url'], PHP_URL_PATH) ?: '/', $content['pages']),
            'missing_assets' => array_keys($missing),
            'assets' => $local,
            'logo_palette' => $this->palette($source . '/images/03_logo-white.png'),
        ];
    }

    public static function sourcePath(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new RuntimeException('Invalid source asset URL.');
        }
        if (($parts['path'] ?? '') === '/_next/image') {
            parse_str($parts['query'] ?? '', $query);
            if (!isset($query['url']) || !is_string($query['url'])) {
                throw new RuntimeException('Missing optimized image source path.');
            }
            return parse_url($query['url'], PHP_URL_PATH) ?: $query['url'];
        }
        return $parts['path'] ?? '/';
    }

    private function json(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Content source file is missing.');
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Content source must contain structured records.');
        }
        return $decoded;
    }

    private function palette(string $path): array
    {
        $image = imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw new RuntimeException('Cannot inspect logo colors.');
        }
        $colors = [];
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($rgba['alpha'] > 10) {
                    continue;
                }
                $hex = sprintf('#%02X%02X%02X', $rgba['red'], $rgba['green'], $rgba['blue']);
                $colors[$hex] = ($colors[$hex] ?? 0) + 1;
            }
        }
        arsort($colors);
        return array_slice($colors, 0, 12, true);
    }
}
