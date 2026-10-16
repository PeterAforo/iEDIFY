<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Services;

/**
 * Generates fixed-width image renditions next to the stored original so
 * templates can serve appropriately sized files via srcset. Renditions are
 * never upscaled beyond the source; WebP is preferred with JPEG fallback.
 *
 * Files follow the convention {sha256}_w{width}.{ext} in the same directory
 * as the original {sha256}.{ext} asset, so no schema changes are needed and
 * generation can be repeated or done lazily on first request.
 */
final class ImageProcessor
{
    /** @var list<int> */
    public const WIDTHS = [480, 960, 1600, 2400];

    private const WEBP_QUALITY = 82;
    private const JPEG_QUALITY = 88;

    /**
     * Generate every rendition width below the source width. Returns the
     * widths actually written; a source that cannot be decoded yields [].
     *
     * @return list<int>
     */
    public function generate(string $sourcePath): array
    {
        if (!extension_loaded('gd')) {
            return [];
        }
        $contents = @file_get_contents($sourcePath);
        if ($contents === false) {
            return [];
        }
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return [];
        }
        $width = imagesx($source);
        $dir = dirname($sourcePath);
        $hash = basename($sourcePath);
        $dot = strrpos($hash, '.');
        if ($dot !== false) {
            $hash = substr($hash, 0, $dot);
        }
        $written = [];
        foreach (self::WIDTHS as $target) {
            if ($target >= $width) {
                continue;
            }
            $path = self::renditionPath($dir, $hash, $target);
            if ($path !== null && is_file($path)) {
                $written[] = $target;
                continue;
            }
            $scaled = imagescale($source, $target);
            if ($scaled === false) {
                continue;
            }
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $this->sharpen($scaled);
            $dest = $dir . '/' . $hash . '_w' . $target . (function_exists('imagewebp') ? '.webp' : '.jpg');
            $saved = str_ends_with($dest, '.webp')
                ? @imagewebp($scaled, $dest, self::WEBP_QUALITY)
                : @imagejpeg($scaled, $dest, self::JPEG_QUALITY);
            if ($saved) {
                $written[] = $target;
            }
        }
        return $written;
    }

    /**
     * Path of the rendition for a given width, or null when none should
     * exist (width not allowed or at/above the source width).
     */
    public static function renditionPath(string $dir, string $hash, int $width): ?string
    {
        if (!in_array($width, self::WIDTHS, true)) {
            return null;
        }
        foreach (['webp', 'jpg'] as $ext) {
            $candidate = $dir . '/' . $hash . '_w' . $width . '.' . $ext;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return $dir . '/' . $hash . '_w' . $width . (function_exists('imagewebp') ? '.webp' : '.jpg');
    }

    /** MIME type served for a rendition file. */
    public static function renditionMime(string $path): string
    {
        return str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg';
    }

    /** Mild unsharp-style convolution so downscales stay crisp. */
    private function sharpen(\GdImage $image): void
    {
        @imageconvolution($image, [
            [-1, -1, -1],
            [-1, 18, -1],
            [-1, -1, -1],
        ], 10, 0);
    }
}
