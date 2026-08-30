<?php

declare(strict_types=1);

namespace RosinTracker\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $post,
        public array $files,
        public array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $normalizedPath = '/' . trim(rawurldecode(is_string($path) ? $path : '/'), '/');
        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: $normalizedPath,
            query: $_GET,
            post: $_POST,
            files: $_FILES,
            server: $_SERVER,
        );
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function rawInput(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return list<string> */
    public function arrayInput(string $key): array
    {
        $value = $this->post[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value,
        ));
    }
}
