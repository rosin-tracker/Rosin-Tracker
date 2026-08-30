<?php

declare(strict_types=1);

namespace RosinTracker\Domain;

use JsonException;
use RuntimeException;

final class Json
{
    private function __construct()
    {
    }

    public static function encode(mixed $value, int $maximumBytes = 262144): string
    {
        try {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $error) {
            throw new ValidationException(['value' => 'The value cannot be represented as JSON.']);
        }

        if (strlen($json) > $maximumBytes) {
            throw new ValidationException(['value' => 'The saved value is too large.']);
        }

        return $json;
    }

    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Stored JSON is invalid.', previous: $error);
        }
    }

    /** @return array<string, mixed> */
    public static function decodeObject(string $json): array
    {
        $value = self::decode($json);
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Stored template JSON must be an object.');
        }

        return $value;
    }
}
