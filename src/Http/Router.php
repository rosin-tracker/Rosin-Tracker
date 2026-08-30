<?php

declare(strict_types=1);

namespace RosinTracker\Http;

use Closure;

final class Router
{
    /** @var list<array{method: string, pattern: string, handler: Closure}> */
    private array $routes = [];

    public function get(string $pattern, Closure $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, Closure $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function dispatch(Request $request): ?Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $parameters = $this->match($route['pattern'], $request->path);
            if ($parameters !== null) {
                return ($route['handler'])($request, ...$parameters);
            }
        }

        return null;
    }

    private function add(string $method, string $pattern, Closure $handler): void
    {
        $normalized = '/' . trim($pattern, '/');
        $this->routes[] = [
            'method' => $method,
            'pattern' => $normalized === '//' ? '/' : $normalized,
            'handler' => $handler,
        ];
    }

    /** @return list<string>|null */
    private function match(string $pattern, string $path): ?array
    {
        if ($pattern === '/' && $path === '/') {
            return [];
        }

        $patternSegments = explode('/', trim($pattern, '/'));
        $pathSegments = explode('/', trim($path, '/'));
        if (count($patternSegments) !== count($pathSegments)) {
            return null;
        }

        $parameters = [];
        foreach ($patternSegments as $index => $segment) {
            $actual = $pathSegments[$index];
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $name = substr($segment, 1, -1);
                if (str_ends_with($name, ':id')) {
                    if (!ctype_digit($actual) || (int) $actual < 1) {
                        return null;
                    }
                }
                $parameters[] = $actual;
                continue;
            }

            if ($segment !== $actual) {
                return null;
            }
        }

        return $parameters;
    }
}
