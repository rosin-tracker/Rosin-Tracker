<?php

declare(strict_types=1);

namespace RosinTracker\Domain;

use InvalidArgumentException;
use RosinTracker\Support\Units;
use Throwable;

final class PassFormNormalizer
{
    public const MAX_PASSES = 20;

    /**
     * Validate one pass and convert its temperature and pressure to storage units.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function normalize(array $input, string $unitSystem = Units::METRIC): PassData
    {
        $errors = [];
        $system = $this->unitSystem($input, $unitSystem, $errors);
        $temperatureUnit = $this->unit(
            self::value($input, 'temperature_unit', 'temperatureUnit'),
            Units::temperatureUnitForSystem($system),
            'temperature_unit',
            [Units::class, 'normalizeTemperatureUnit'],
            $errors,
        );
        $pressureUnit = $this->unit(
            self::value($input, 'pressure_unit', 'pressureUnit'),
            Units::pressureUnitForSystem($system),
            'pressure_unit',
            [Units::class, 'normalizePressureUnit'],
            $errors,
        );

        $temperature = $this->number(
            self::value($input, 'temperature', 'temperature_c', 'temperatureC'),
            'temperature',
            false,
            $errors,
        );
        $pressure = $this->number(
            self::value($input, 'pressure', 'pressure_bar', 'pressureBar'),
            'pressure',
            true,
            $errors,
        );
        $preheat = $this->integer(
            self::value($input, 'preheat', 'preheat_seconds', 'preheatSeconds'),
            'preheat',
            true,
            $errors,
        );
        $duration = $this->integer(
            self::value(
                $input,
                'press_duration',
                'press_duration_seconds',
                'pressDuration',
                'pressDurationSeconds',
            ),
            'press_duration',
            true,
            $errors,
        );

        $temperatureC = $this->convert(
            $temperature,
            static fn (float $value): float => Units::temperatureToCelsius($value, $temperatureUnit),
            'temperature',
            $errors,
        );
        $pressureBar = $this->convert(
            $pressure,
            static fn (float $value): float => Units::pressureToBar($value, $pressureUnit),
            'pressure',
            $errors,
        );

        if ($temperatureC !== null && ($temperatureC < -50 || $temperatureC > 300)) {
            $errors['temperature'] = 'Enter a temperature between -50 and 300 °C.';
        }
        if ($pressureBar !== null && $pressureBar < 0) {
            $errors['pressure'] = 'Pressure cannot be negative.';
        }
        if ($preheat !== null && ($preheat < 0 || $preheat > 7200)) {
            $errors['preheat'] = 'Enter a preheat time between 0 and 7200 seconds.';
        }
        if ($duration !== null && ($duration < 0 || $duration > 7200)) {
            $errors['press_duration'] = 'Enter a press duration between 0 and 7200 seconds.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new PassData(
            temperatureC: (float) $temperatureC,
            pressureBar: $pressureBar,
            preheatSeconds: $preheat,
            pressDurationSeconds: $duration,
        );
    }

    /**
     * Validate the ordered passes in a New Batch submission.
     *
     * Missing `passes` retains compatibility with the former flat Pass 1
     * payload. Array order is authoritative; client-supplied positions are
     * deliberately ignored.
     *
     * @param array<string, mixed> $input
     * @return non-empty-list<PassData>
     * @throws ValidationException
     */
    public function normalizeMany(array $input, string $unitSystem = Units::METRIC): array
    {
        $hasExplicitPasses = array_key_exists('passes', $input);
        $rawPasses = $hasExplicitPasses ? $input['passes'] : [$input];
        if (!is_array($rawPasses)) {
            throw new ValidationException(['passes' => 'Press passes are invalid.']);
        }

        $rows = array_values($rawPasses);
        $errors = [];
        if ($rows === []) {
            $errors['passes'] = 'Add at least one press pass.';
        } elseif (count($rows) > self::MAX_PASSES) {
            $errors['passes'] = 'Use no more than ' . self::MAX_PASSES . ' press passes.';
        }

        $passes = [];
        foreach (array_slice($rows, 0, self::MAX_PASSES) as $index => $row) {
            if (!is_array($row)) {
                $errors["passes.{$index}"] = 'This press pass is invalid.';
                continue;
            }

            $passInput = array_replace([
                'unit_system' => self::value($input, 'unit_system', 'unitSystem'),
                'temperature_unit' => self::value($input, 'temperature_unit', 'temperatureUnit'),
                'pressure_unit' => self::value($input, 'pressure_unit', 'pressureUnit'),
            ], $row);

            try {
                $passes[] = $this->normalize($passInput, $unitSystem);
            } catch (ValidationException $error) {
                foreach ($error->errors() as $field => $message) {
                    $errors["passes.{$index}.{$field}"] = $message;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var non-empty-list<PassData> $passes */
        return $passes;
    }

    /** @param array<string, string> $errors */
    private function unitSystem(array $input, string $fallback, array &$errors): string
    {
        $raw = self::value($input, 'unit_system', 'unitSystem');
        try {
            return Units::normalizeSystem($this->blank($raw) ? $fallback : (string) $raw);
        } catch (InvalidArgumentException) {
            $errors['unit_system'] = 'Choose metric or imperial units.';
            return Units::METRIC;
        }
    }

    /**
     * @param callable(string):string $normalizer
     * @param array<string, string> $errors
     */
    private function unit(
        mixed $raw,
        string $fallback,
        string $field,
        callable $normalizer,
        array &$errors,
    ): string {
        try {
            return $normalizer($this->blank($raw) ? $fallback : (string) $raw);
        } catch (InvalidArgumentException) {
            $errors[$field] = 'Choose a supported unit.';
            return $fallback;
        }
    }

    /** @param array<string, string> $errors */
    private function number(mixed $raw, string $field, bool $optional, array &$errors): ?float
    {
        if ($this->blank($raw)) {
            if (!$optional) {
                $errors[$field] = 'This field is required.';
            }
            return null;
        }
        if (!is_scalar($raw) || !is_numeric(trim((string) $raw))) {
            $errors[$field] = 'Enter a valid number.';
            return null;
        }
        $value = (float) $raw;
        if (!is_finite($value)) {
            $errors[$field] = 'Enter a finite number.';
            return null;
        }
        return $value;
    }

    /** @param array<string, string> $errors */
    private function integer(mixed $raw, string $field, bool $optional, array &$errors): ?int
    {
        if ($this->blank($raw)) {
            if (!$optional) {
                $errors[$field] = 'This field is required.';
            }
            return null;
        }
        if (!is_scalar($raw) || preg_match('/^-?\d+$/D', trim((string) $raw)) !== 1) {
            $errors[$field] = 'Enter a whole number.';
            return null;
        }
        return (int) $raw;
    }

    /**
     * @param callable(float):float $converter
     * @param array<string, string> $errors
     */
    private function convert(?float $value, callable $converter, string $field, array &$errors): ?float
    {
        if ($value === null) {
            return null;
        }
        try {
            $converted = $converter($value);
            if (!is_finite($converted)) {
                throw new InvalidArgumentException('Non-finite conversion.');
            }
            return $converted;
        } catch (Throwable) {
            $errors[$field] = 'This value could not be converted.';
            return null;
        }
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_scalar($value) && trim((string) $value) === '');
    }

    private static function value(array $input, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }
        }
        return null;
    }
}
