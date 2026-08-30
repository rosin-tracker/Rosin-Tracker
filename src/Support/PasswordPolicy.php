<?php

declare(strict_types=1);

namespace RosinTracker\Support;

final class PasswordPolicy
{
    public const MINIMUM_LENGTH = 8;
    public const MAXIMUM_LENGTH = 128;

    /** @return array<string, string> */
    public static function validate(string $password, string $field = 'password'): array
    {
        $length = mb_strlen($password);
        if ($length < self::MINIMUM_LENGTH) {
            return [$field => 'Use at least ' . self::MINIMUM_LENGTH . ' characters.'];
        }
        if ($length > self::MAXIMUM_LENGTH) {
            return [$field => 'Use no more than ' . self::MAXIMUM_LENGTH . ' characters.'];
        }
        return [];
    }

    /** @return array<string, string> */
    public static function validateWithConfirmation(
        string $password,
        string $confirmation,
        string $passwordField = 'password',
        string $confirmationField = 'password_confirmation',
    ): array {
        $errors = self::validate($password, $passwordField);
        if (!hash_equals($password, $confirmation)) {
            $errors[$confirmationField] = 'The passwords do not match.';
        }
        return $errors;
    }
}
