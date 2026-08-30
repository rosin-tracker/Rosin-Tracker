<?php

declare(strict_types=1);

namespace RosinTracker\Support;

use InvalidArgumentException;
use Stringable;

/**
 * A canonical browser-facing application origin.
 */
final readonly class ExternalUrl implements Stringable
{
    private const MAXIMUM_LENGTH = 2048;

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Enter the application URL.');
        }
        if (strlen($value) > self::MAXIMUM_LENGTH) {
            throw new InvalidArgumentException('The application URL cannot be longer than 2048 characters.');
        }
        if (
            preg_match('/[\x00-\x20\x7f]/', $value) === 1
            || preg_match('/[\p{Z}\p{Cc}\p{Cf}]/u', $value) === 1
        ) {
            throw new InvalidArgumentException('The application URL cannot contain whitespace or control characters.');
        }
        if (str_contains($value, '\\')) {
            throw new InvalidArgumentException('The application URL cannot contain backslashes.');
        }

        if (preg_match(
            '/\A(?<scheme>[A-Za-z][A-Za-z0-9+.-]*):\/\/(?<authority>[^\/?#]+)\/?\z/',
            $value,
            $origin,
        ) !== 1) {
            throw new InvalidArgumentException(
                'Enter only the application origin, including its scheme, hostname, and optional port.',
            );
        }

        $scheme = strtolower($origin['scheme']);
        if (!in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidArgumentException(
                'The application URL must use HTTPS. HTTP is allowed only for localhost.',
            );
        }

        $authority = $origin['authority'];
        if (str_contains($authority, '@')) {
            throw new InvalidArgumentException('The application URL cannot include a username or password.');
        }

        [$host, $hostForPolicy, $port] = str_starts_with($authority, '[')
            ? self::parseIpv6Authority($authority)
            : self::parseHostnameAuthority($authority);

        if ($scheme === 'http' && !in_array($hostForPolicy, ['localhost', '127.0.0.1'], true)) {
            throw new InvalidArgumentException(
                'The application URL must use HTTPS. HTTP is allowed only for localhost.',
            );
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $portSuffix = $port === null || $port === $defaultPort ? '' : ':' . $port;

        return new self($scheme . '://' . $host . $portSuffix);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isHttps(): bool
    {
        return str_starts_with($this->value, 'https://');
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @return array{string, string, ?int}
     */
    private static function parseIpv6Authority(string $authority): array
    {
        if (preg_match(
            '/\A\[(?<host>[^\]]+)\](?::(?<port>[0-9]+))?\z/',
            $authority,
            $parts,
            PREG_UNMATCHED_AS_NULL,
        ) !== 1) {
            throw new InvalidArgumentException('Enter a valid hostname and optional port.');
        }

        $host = strtolower($parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException('Enter a valid hostname or IP address.');
        }

        return ['[' . $host . ']', $host, self::normalizePort($parts['port'])];
    }

    /**
     * @return array{string, string, ?int}
     */
    private static function parseHostnameAuthority(string $authority): array
    {
        if (preg_match(
            '/\A(?<host>[^:]+)(?::(?<port>[0-9]+))?\z/',
            $authority,
            $parts,
            PREG_UNMATCHED_AS_NULL,
        ) !== 1) {
            throw new InvalidArgumentException('Enter a valid hostname and optional port.');
        }

        $host = self::normalizeHostname($parts['host']);

        return [$host, $host, self::normalizePort($parts['port'])];
    }

    private static function normalizeHostname(string $host): string
    {
        $host = strtolower($host);
        if ($host === 'localhost') {
            return $host;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }
        if (preg_match('/\A[0-9.]+\z/', $host) === 1 || strlen($host) > 253) {
            throw new InvalidArgumentException('Enter a valid hostname or IP address.');
        }

        foreach (explode('.', $host) as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/', $label) !== 1
            ) {
                throw new InvalidArgumentException('Enter a valid hostname or IP address.');
            }
        }

        return $host;
    }

    private static function normalizePort(?string $port): ?int
    {
        if ($port === null) {
            return null;
        }
        if (strlen($port) > 5) {
            throw new InvalidArgumentException('Enter a valid port between 1 and 65535.');
        }

        $normalized = (int) $port;
        if ($normalized < 1 || $normalized > 65535) {
            throw new InvalidArgumentException('Enter a valid port between 1 and 65535.');
        }

        return $normalized;
    }
}
