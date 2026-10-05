<?php

declare(strict_types=1);

namespace IEdify\Core\View;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class View
{
    private Environment $twig;
    private array $manifest;

    public function __construct(string $root)
    {
        $this->twig = new Environment(new FilesystemLoader($root . '/resources/views'), ['autoescape' => 'html', 'strict_variables' => true]);
        $path = $root . '/public/build/.vite/manifest.json';
        $this->manifest = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        $this->twig->addFunction(new TwigFunction('asset', $this->asset(...)));
        $this->twig->addFunction(new TwigFunction('asset_styles', $this->styles(...)));
    }

    public function asset(string $entry): string
    {
        if (!isset($this->manifest[$entry]['file'])) {
            throw new RuntimeException('Compiled assets are missing. Run npm run build.');
        }
        return '/build/' . $this->manifest[$entry]['file'];
    }

    public function styles(string $entry): array
    {
        return array_map(static fn (string $path): string => '/build/' . $path, $this->manifest[$entry]['css'] ?? []);
    }

    public function render(string $template, array $data = [], int $status = 200): Response
    {
        return new Response($this->twig->render($template, $data), $status);
    }
}
