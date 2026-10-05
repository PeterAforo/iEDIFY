<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Services;

use InvalidArgumentException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class Sections
{
    public function validate(array $sections): array
    {
        if (!array_is_list($sections) || count($sections) > 50 || strlen(json_encode($sections, JSON_THROW_ON_ERROR)) > 200000) {
            throw new InvalidArgumentException('Page sections exceed the permitted size.');
        }
        return array_map($this->block(...), $sections);
    }

    private function block(mixed $block): array
    {
        if (!is_array($block) || !isset($block['type']) || !is_string($block['type'])) {
            throw new InvalidArgumentException('A section requires a valid type.');
        }
        $type = $block['type'];
        return match ($type) {
            'heading', 'text' => ['type' => $type, 'text' => $this->text($block, 'text', $type === 'heading' ? 250 : 60000)],
            'rich_text' => ['type' => $type, 'html' => (new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements()))->sanitize($this->text($block, 'html', 60000))],
            'image' => ['type' => $type, 'media_id' => $this->mediaId($block), 'alt' => $this->text($block, 'alt', 500)],
            'quote' => ['type' => $type, 'text' => $this->text($block, 'text', 5000), 'attribution' => $this->text($block, 'attribution', 250)],
            'cta' => ['type' => $type, 'label' => $this->text($block, 'label', 100), 'url' => $this->url($this->text($block, 'url', 1000))],
            'statistic' => $this->statistic($block),
            'cards', 'gallery', 'accordion', 'timeline' => $this->collection($block),
            default => throw new InvalidArgumentException('This section type is not supported.'),
        };
    }

    private function text(array $data, string $key, int $maximum): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Invalid section field: ' . $key);
        }
        return trim($value);
    }

    private function mediaId(array $block): int
    {
        $id = filter_var($block['media_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException('A valid media record is required.');
        }
        return $id;
    }

    private function url(string $url): string
    {
        if (preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new InvalidArgumentException('Invalid link URL.');
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_USER) === null) {
            return $url;
        }
        throw new InvalidArgumentException('Links must be a site-relative path or HTTPS URL.');
    }

    private function statistic(array $block): array
    {
        $kind = $block['kind'] ?? null;
        if (!in_array($kind, ['target', 'context', 'approved_actual'], true)) {
            throw new InvalidArgumentException('Statistics require an explicit classification.');
        }
        if ($kind === 'approved_actual') {
            throw new InvalidArgumentException('Actual results must be selected from the approved impact-report module.');
        }
        return ['type' => 'statistic', 'kind' => $kind, 'label' => $this->text($block, 'label', 200), 'value' => $this->text($block, 'value', 100), 'source' => $this->text($block, 'source', 1000), 'period' => $this->text($block, 'period', 100)];
    }

    private function collection(array $block): array
    {
        $items = $block['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 24) {
            throw new InvalidArgumentException('A collection requires between 1 and 24 items.');
        }
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid collection item.');
            }
            $normalized[] = $block['type'] === 'gallery'
                ? ['media_id' => $this->mediaId($item), 'alt' => $this->text($item, 'alt', 500)]
                : ['title' => $this->text($item, 'title', 250), 'text' => $this->text($item, 'text', 5000)];
        }
        return ['type' => $block['type'], 'items' => $normalized];
    }
}
