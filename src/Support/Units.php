<?php

declare(strict_types=1);

namespace RosinTracker\Support;

use InvalidArgumentException;

/**
 * Exact unit conversions used at the form/domain boundary.
 *
 * Persisted batches always use grams, Celsius, bar, and millimetres. Display
 * preferences must never change the meaning of values already in storage.
 */
final class Units
{
    public const METRIC = 'metric';
    public const IMPERIAL = 'imperial';

    private const GRAMS_PER_OUNCE = 28.349523125;
    private const BAR_PER_PSI = 0.0689475729317831;
    private const MILLIMETRES_PER_INCH = 25.4;

    private function __construct()
    {
    }

    public static function normalizeSystem(string $system): string
    {
        $normalized = strtolower(trim($system));
        if (!in_array($normalized, [self::METRIC, self::IMPERIAL], true)) {
            throw new InvalidArgumentException('The unit system must be metric or imperial.');
        }

        return $normalized;
    }

    public static function weightUnitForSystem(string $system): string
    {
        return self::normalizeSystem($system) === self::METRIC ? 'g' : 'oz';
    }

    public static function temperatureUnitForSystem(string $system): string
    {
        return self::normalizeSystem($system) === self::METRIC ? 'c' : 'f';
    }

    public static function pressureUnitForSystem(string $system): string
    {
        return self::normalizeSystem($system) === self::METRIC ? 'bar' : 'psi';
    }

    public static function lengthUnitForSystem(string $system): string
    {
        return self::normalizeSystem($system) === self::METRIC ? 'mm' : 'in';
    }

    public static function weightToGrams(float $value, string $unit): float
    {
        self::assertFinite($value);

        return self::roundCanonical(match (self::normalizeWeightUnit($unit)) {
            'g' => $value,
            'oz' => $value * self::GRAMS_PER_OUNCE,
        });
    }

    public static function gramsToWeight(float $grams, string $unit): float
    {
        self::assertFinite($grams);

        return self::roundDisplay(match (self::normalizeWeightUnit($unit)) {
            'g' => $grams,
            'oz' => $grams / self::GRAMS_PER_OUNCE,
        });
    }

    public static function temperatureToCelsius(float $value, string $unit): float
    {
        self::assertFinite($value);

        return self::roundCanonical(match (self::normalizeTemperatureUnit($unit)) {
            'c' => $value,
            'f' => ($value - 32.0) * (5.0 / 9.0),
        });
    }

    public static function celsiusToTemperature(float $celsius, string $unit): float
    {
        self::assertFinite($celsius);

        return self::roundDisplay(match (self::normalizeTemperatureUnit($unit)) {
            'c' => $celsius,
            'f' => ($celsius * (9.0 / 5.0)) + 32.0,
        });
    }

    public static function pressureToBar(float $value, string $unit): float
    {
        self::assertFinite($value);

        return self::roundCanonical(match (self::normalizePressureUnit($unit)) {
            'bar' => $value,
            'psi' => $value * self::BAR_PER_PSI,
        });
    }

    public static function barToPressure(float $bar, string $unit): float
    {
        self::assertFinite($bar);

        return self::roundDisplay(match (self::normalizePressureUnit($unit)) {
            'bar' => $bar,
            'psi' => $bar / self::BAR_PER_PSI,
        });
    }

    public static function lengthToMillimetres(float $value, string $unit): float
    {
        self::assertFinite($value);

        return self::roundCanonical(match (self::normalizeLengthUnit($unit)) {
            'mm' => $value,
            'in' => $value * self::MILLIMETRES_PER_INCH,
        });
    }

    public static function millimetresToLength(float $millimetres, string $unit): float
    {
        self::assertFinite($millimetres);

        return self::roundDisplay(match (self::normalizeLengthUnit($unit)) {
            'mm' => $millimetres,
            'in' => $millimetres / self::MILLIMETRES_PER_INCH,
        });
    }

    public static function normalizeWeightUnit(string $unit): string
    {
        return match (strtolower(trim($unit))) {
            'g', 'gram', 'grams' => 'g',
            'oz', 'ounce', 'ounces' => 'oz',
            default => throw new InvalidArgumentException('The weight unit must be grams or ounces.'),
        };
    }

    public static function normalizeTemperatureUnit(string $unit): string
    {
        return match (strtolower(trim($unit))) {
            'c', 'celsius', '°c' => 'c',
            'f', 'fahrenheit', '°f' => 'f',
            default => throw new InvalidArgumentException('The temperature unit must be Celsius or Fahrenheit.'),
        };
    }

    public static function normalizePressureUnit(string $unit): string
    {
        return match (strtolower(trim($unit))) {
            'bar' => 'bar',
            'psi' => 'psi',
            default => throw new InvalidArgumentException('The pressure unit must be bar or PSI.'),
        };
    }

    public static function normalizeLengthUnit(string $unit): string
    {
        return match (strtolower(trim($unit))) {
            'mm', 'millimetre', 'millimetres', 'millimeter', 'millimeters' => 'mm',
            'in', 'inch', 'inches', '"' => 'in',
            default => throw new InvalidArgumentException('The bag dimension unit must be millimetres or inches.'),
        };
    }

    private static function assertFinite(float $value): void
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('Measurements must be finite numbers.');
        }
    }

    private static function roundCanonical(float $value): float
    {
        return round($value, 6);
    }

    private static function roundDisplay(float $value): float
    {
        return round($value, 4);
    }
}
