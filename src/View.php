<?php

declare(strict_types=1);

namespace RosinTracker;

use RuntimeException;

final readonly class View
{
    public function __construct(private Config $config)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'app'): string
    {
        $templatePath = $this->config->basePath . '/templates/' . $template . '.php';
        if (!is_file($templatePath)) {
            throw new RuntimeException('View template not found: ' . $template);
        }

        $view = $this;
        extract($data, EXTR_SKIP);
        ob_start();
        require $templatePath;
        $content = (string) ob_get_clean();

        if ($layout === null) {
            return $content;
        }

        $layoutPath = $this->config->basePath . '/templates/layouts/' . $layout . '.php';
        if (!is_file($layoutPath)) {
            throw new RuntimeException('View layout not found: ' . $layout);
        }

        ob_start();
        require $layoutPath;
        return (string) ob_get_clean();
    }

    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function asset(string $path): string
    {
        $clean = '/' . ltrim($path, '/');
        $file = $this->config->basePath . '/public' . $clean;
        $version = is_file($file) ? (string) filemtime($file) : '1';
        return $clean . '?v=' . rawurlencode($version);
    }
}
