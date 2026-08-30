<?php

declare(strict_types=1);

namespace RosinTracker\Security;

use RuntimeException;

final class RecoveryCodes
{
    public const COUNT = 8;

    /** @return array{codes: list<string>, hashes: list<string>} */
    public function generate(): array
    {
        $codes = [];
        $hashes = [];
        for ($index = 0; $index < self::COUNT; $index++) {
            $plain = strtoupper(bin2hex(random_bytes(10)));
            $code = implode('-', str_split($plain, 5));
            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $hash = password_hash($this->normalize($code), $algorithm);
            if (!is_string($hash)) {
                throw new RuntimeException('A recovery code could not be secured.');
            }
            $codes[] = $code;
            $hashes[] = $hash;
        }
        return ['codes' => $codes, 'hashes' => $hashes];
    }

    public function matches(string $candidate, string $hash): bool
    {
        $normalized = $this->normalize($candidate);
        return strlen($normalized) === 20 && ctype_xdigit($normalized) && password_verify($normalized, $hash);
    }

    public function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^a-fA-F0-9]/', '', trim($code)));
    }
}
