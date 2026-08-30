<?php

declare(strict_types=1);

namespace RosinTracker\Domain;

use InvalidArgumentException;

final class ValidationException extends InvalidArgumentException
{
    /** @param array<string, string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The submitted values could not be validated.');
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
