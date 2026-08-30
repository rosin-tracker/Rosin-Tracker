<?php

declare(strict_types=1);

namespace RosinTracker\Domain;

use InvalidArgumentException;
use RosinTracker\Support\Units;

/** A reusable settings-only batch template in canonical units. */
final readonly class BatchTemplateData
{
    private const MAX_STRAIN_AMOUNT_G = 1_000_000.0;

    /**
     * Legacy first-pass properties remain available to older callers. The
     * ordered `passes` list is authoritative for schema version 2+ templates.
     *
     * @param list<array{position:int,brand?:string|null,micron:int,widthMm:float,lengthMm:float,layer:int}> $bags
     * @param list<PassData> $passes
     * @param list<string> $strains
     * @param list<float|null> $strainAmountsG Canonical amounts aligned with $strains; an empty list means all unknown.
     */
    public function __construct(
        public string $startMaterial,
        public float $temperatureC,
        public ?float $pressureBar,
        public ?float $pressCapacityTons,
        public ?float $humidityPercent,
        public ?int $pressDurationSeconds,
        public ?int $preheatSeconds,
        public int $numberOfPresses,
        public array $bags,
        public array $passes = [],
        public array $strains = [],
        public array $strainAmountsG = [],
    ) {
    }

    /** @param list<PassData> $passes */
    public static function fromBatch(BatchData $batch, array $passes = []): self
    {
        if ($passes === []) {
            $passes = [PassData::fromBatch($batch)];
        }
        self::assertPassObjects($passes);
        if (count($passes) > PassFormNormalizer::MAX_PASSES) {
            throw new ValidationException([
                'passes' => 'Use no more than ' . PassFormNormalizer::MAX_PASSES . ' press passes.',
            ]);
        }

        $passes = array_values($passes);
        $first = $passes[0];
        $strainAmountsG = $batch->strainAmountsG;
        // Single-strain batches use the total starting amount as their full
        // blend, even when the batch form did not store a separate breakdown.
        if (count($batch->strains) === 1
            && ($strainAmountsG === [] || $strainAmountsG === [null])) {
            $strainAmountsG = [$batch->startAmountG];
        }

        return new self(
            startMaterial: $batch->startMaterial,
            temperatureC: $first->temperatureC,
            pressureBar: $first->pressureBar,
            pressCapacityTons: $batch->pressCapacityTons,
            humidityPercent: $batch->humidityPercent,
            pressDurationSeconds: $first->pressDurationSeconds,
            preheatSeconds: $first->preheatSeconds,
            numberOfPresses: count($passes),
            bags: $batch->bags,
            passes: $passes,
            strains: $batch->strains,
            strainAmountsG: $strainAmountsG,
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];
        $version = $payload['schemaVersion'] ?? null;
        if (!is_int($version) || !in_array($version, [1, 2, 3], true)) {
            $errors['payload'] = 'The template version is not supported.';
        }

        $allowed = [
            'schemaVersion', 'startMaterial', 'temperatureC', 'pressureBar',
            'pressCapacityTons', 'humidityPercent', 'pressDurationSeconds',
            'preheatSeconds', 'numberOfPresses', 'bags',
        ];
        if (in_array($version, [2, 3], true)) {
            $allowed[] = 'passes';
        }
        if ($version === 3) {
            $allowed[] = 'strains';
            $allowed[] = 'strainAmountsG';
        }
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors['payload'] = 'The template contains batch-specific or unsupported values.';
                break;
            }
        }

        $material = self::text($payload['startMaterial'] ?? null, 120, 'startMaterial', $errors);
        $capacity = self::float($payload['pressCapacityTons'] ?? null, true, 'pressCapacityTons', $errors);
        $humidity = self::float($payload['humidityPercent'] ?? null, true, 'humidityPercent', $errors);
        $bags = self::bags($payload['bags'] ?? null, $errors);

        if ($capacity !== null && ($capacity <= 0 || $capacity > 1000)) {
            $errors['pressCapacityTons'] = 'The template press capacity is out of range.';
        }
        if ($humidity !== null && ($humidity < 0 || $humidity > 100)) {
            $errors['humidityPercent'] = 'The template humidity is out of range.';
        }

        $passes = in_array($version, [2, 3], true)
            ? self::passes($payload['passes'] ?? null, $errors)
            : self::legacyPasses($payload, $errors);

        if (in_array($version, [2, 3], true) && array_key_exists('numberOfPresses', $payload)) {
            $declaredCount = self::integer(
                $payload['numberOfPresses'],
                false,
                'numberOfPresses',
                $errors,
            );
            if ($declaredCount !== null && $passes !== [] && $declaredCount !== count($passes)) {
                $errors['numberOfPresses'] = 'The template press count does not match its passes.';
            }
        }

        [$strains, $strainAmountsG] = $version === 3
            ? self::canonicalStrains(
                $payload['strains'] ?? [],
                $payload['strainAmountsG'] ?? [],
                $errors,
            )
            : [[], []];

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var non-empty-list<PassData> $passes */
        $first = $passes[0];
        return new self(
            startMaterial: (string) $material,
            temperatureC: $first->temperatureC,
            pressureBar: $first->pressureBar,
            pressCapacityTons: $capacity,
            humidityPercent: $humidity,
            pressDurationSeconds: $first->pressDurationSeconds,
            preheatSeconds: $first->preheatSeconds,
            numberOfPresses: count($passes),
            bags: $bags,
            passes: $passes,
            strains: $strains,
            strainAmountsG: $strainAmountsG,
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $passes = $this->resolvedPasses();
        $first = $passes[0];
        $errors = [];
        [$strains, $strainAmountsG] = self::canonicalStrains(
            $this->strains,
            $this->strainAmountsG,
            $errors,
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'schemaVersion' => 3,
            'startMaterial' => $this->startMaterial,
            // Keep the Pass 1 summary keys so older readers can still display
            // a useful template without understanding the ordered sequence.
            'temperatureC' => $first->temperatureC,
            'pressureBar' => $first->pressureBar,
            'pressCapacityTons' => $this->pressCapacityTons,
            'humidityPercent' => $this->humidityPercent,
            'pressDurationSeconds' => $first->pressDurationSeconds,
            'preheatSeconds' => $first->preheatSeconds,
            'numberOfPresses' => count($passes),
            'passes' => array_map(
                static fn (PassData $pass): array => $pass->toTemplatePayload(),
                $passes,
            ),
            'strains' => $strains,
            'strainAmountsG' => $strainAmountsG,
            'bags' => array_map(
                static function (array $bag): array {
                    $payload = [
                        'micron' => $bag['micron'],
                        'widthMm' => $bag['widthMm'],
                        'lengthMm' => $bag['lengthMm'],
                        'layer' => $bag['layer'],
                    ];
                    if (($bag['brand'] ?? null) !== null) {
                        $payload['brand'] = $bag['brand'];
                    }
                    return $payload;
                },
                $this->bags,
            ),
        ];
    }

    /**
     * Return an empty batch form with only reusable settings filled.
     *
     * @return array<string, mixed>
     */
    public function toFormValues(string $unitSystem): array
    {
        $system = Units::normalizeSystem($unitSystem);
        $temperatureUnit = Units::temperatureUnitForSystem($system);
        $pressureUnit = Units::pressureUnitForSystem($system);
        $lengthUnit = Units::lengthUnitForSystem($system);
        $weightUnit = Units::weightUnitForSystem($system);
        $passes = $this->resolvedPasses();
        $first = $passes[0];
        $strainAmounts = [];
        foreach ($this->strains as $position => $_strain) {
            $amountG = $this->strainAmountsG[$position] ?? null;
            $strainAmounts[] = $amountG === null
                ? ''
                : Units::gramsToWeight($amountG, $weightUnit);
        }
        $startAmount = $this->strainAmountsG === []
            ? ''
            : Units::gramsToWeight(array_sum($this->strainAmountsG), $weightUnit);

        $passValues = array_map(static function (PassData $pass) use ($system): array {
            $values = $pass->toFormValues($system);
            return [
                'temperature' => $values['temperature'],
                'pressure' => $values['pressure'],
                'preheat' => $values['preheat'],
                'press_duration' => $values['press_duration'],
            ];
        }, $passes);

        return [
            'pressed_at' => '',
            'pressed_at_mode' => 'automatic',
            'strains' => $this->strains,
            'strain_amounts' => $strainAmounts,
            'start_amount' => $startAmount,
            'yield_amount' => '',
            'notes' => '',
            'unit_system' => $system,
            'weight_unit' => $weightUnit,
            'temperature_unit' => $temperatureUnit,
            'pressure_unit' => $pressureUnit,
            'bag_size_unit' => $lengthUnit,
            'start_material' => $this->startMaterial,
            'temperature' => Units::celsiusToTemperature($first->temperatureC, $temperatureUnit),
            'pressure' => $first->pressureBar === null
                ? ''
                : Units::barToPressure($first->pressureBar, $pressureUnit),
            'press_capacity' => $this->pressCapacityTons ?? '',
            'humidity' => $this->humidityPercent ?? '',
            'press_duration' => $first->pressDurationSeconds ?? '',
            'preheat' => $first->preheatSeconds ?? '',
            'number_of_presses' => count($passes),
            'passes' => $passValues,
            'bags' => array_map(
                static fn (array $bag): array => [
                    'brand' => $bag['brand'] ?? null,
                    'micron' => $bag['micron'],
                    'width' => Units::millimetresToLength($bag['widthMm'], $lengthUnit),
                    'length' => Units::millimetresToLength($bag['lengthMm'], $lengthUnit),
                    'unit' => $lengthUnit,
                    'layer' => $bag['layer'],
                ],
                $this->bags,
            ),
        ];
    }

    /**
     * Replace the reusable strain rows with values submitted in display units.
     * Empty rows are ignored, while an amount without a name and partially
     * completed amount sets are rejected just like the batch form.
     */
    public function withFormStrains(
        mixed $rawStrains,
        mixed $rawAmounts,
        mixed $weightUnit,
        ?self $canonicalFallback = null,
    ): self
    {
        $errors = [];
        try {
            $unit = is_string($weightUnit)
                ? Units::normalizeWeightUnit($weightUnit)
                : throw new InvalidArgumentException();
        } catch (InvalidArgumentException) {
            $errors['weight_unit'] = 'Choose a supported weight unit.';
            $unit = 'g';
        }

        [$strains, $strainAmountsG] = self::formStrains($rawStrains, $rawAmounts, $unit, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $updated = $this->copyWithStrains($strains, $strainAmountsG);
        return $canonicalFallback === null
            ? $updated
            : $updated->preserveUnchangedDisplayedAmounts($canonicalFallback, $unit);
    }

    /** @param list<string> $strains @param list<float|null> $strainAmountsG */
    public function withStrains(array $strains, array $strainAmountsG = []): self
    {
        $errors = [];
        [$strains, $strainAmountsG] = self::canonicalStrains($strains, $strainAmountsG, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->copyWithStrains($strains, $strainAmountsG);
    }

    /** @param list<string> $strains @param list<float|null> $strainAmountsG */
    private function copyWithStrains(array $strains, array $strainAmountsG): self
    {
        return new self(
            startMaterial: $this->startMaterial,
            temperatureC: $this->temperatureC,
            pressureBar: $this->pressureBar,
            pressCapacityTons: $this->pressCapacityTons,
            humidityPercent: $this->humidityPercent,
            pressDurationSeconds: $this->pressDurationSeconds,
            preheatSeconds: $this->preheatSeconds,
            numberOfPresses: $this->numberOfPresses,
            bags: $this->bags,
            passes: $this->passes,
            strains: $strains,
            strainAmountsG: $strainAmountsG,
        );
    }

    private function preserveUnchangedDisplayedAmounts(self $fallback, string $weightUnit): self
    {
        if ($this->strains !== $fallback->strains
            || count($this->strainAmountsG) !== count($fallback->strainAmountsG)) {
            return $this;
        }
        foreach ($this->strainAmountsG as $index => $amountG) {
            $fallbackAmountG = $fallback->strainAmountsG[$index] ?? null;
            if ($amountG === null || $fallbackAmountG === null) {
                if ($amountG !== $fallbackAmountG) {
                    return $this;
                }
                continue;
            }
            $displayRoundTripG = Units::weightToGrams(
                Units::gramsToWeight($fallbackAmountG, $weightUnit),
                $weightUnit,
            );
            if (abs($amountG - $displayRoundTripG) > 0.000001) {
                return $this;
            }
        }

        return $this->copyWithStrains($this->strains, $fallback->strainAmountsG);
    }

    /** @return non-empty-list<PassData> */
    private function resolvedPasses(): array
    {
        if ($this->passes !== []) {
            self::assertPassObjects($this->passes);
            if (count($this->passes) > PassFormNormalizer::MAX_PASSES) {
                throw new InvalidArgumentException('A template cannot contain more than 20 press passes.');
            }
            /** @var non-empty-list<PassData> $passes */
            $passes = array_values($this->passes);
            return $passes;
        }

        $first = new PassData(
            temperatureC: $this->temperatureC,
            pressureBar: $this->pressureBar,
            preheatSeconds: $this->preheatSeconds,
            pressDurationSeconds: $this->pressDurationSeconds,
        );
        $count = max(1, min(PassFormNormalizer::MAX_PASSES, $this->numberOfPresses));
        /** @var non-empty-list<PassData> $passes */
        $passes = array_fill(0, $count, $first);
        return $passes;
    }

    /** @param list<PassData> $passes */
    private static function assertPassObjects(array $passes): void
    {
        if ($passes === []) {
            throw new InvalidArgumentException('A template needs at least one press pass.');
        }
        foreach ($passes as $pass) {
            if (!$pass instanceof PassData) {
                throw new InvalidArgumentException('Template passes must be PassData values.');
            }
        }
    }

    /**
     * @param array<string, string> $errors
     * @return array{list<string>,list<float|null>}
     */
    private static function canonicalStrains(mixed $rawStrains, mixed $rawAmounts, array &$errors): array
    {
        if (!is_array($rawStrains) || !array_is_list($rawStrains) || count($rawStrains) > 20) {
            $errors['strains'] = 'The template strains are invalid.';
            $rawStrains = [];
        }
        if (!is_array($rawAmounts) || !array_is_list($rawAmounts)) {
            $errors['strainAmountsG'] = 'The template strain amounts are invalid.';
            $rawAmounts = [];
        }
        if ($rawAmounts !== [] && count($rawAmounts) !== count($rawStrains)) {
            $errors['strainAmountsG'] = 'The template strain amounts do not match its strains.';
        }

        $strains = [];
        $amounts = [];
        $seen = [];
        $hasAmount = false;
        foreach (array_values($rawStrains) as $index => $rawName) {
            $field = "strains.{$index}";
            $name = self::text($rawName, 120, $field, $errors);
            if ($name === null) {
                continue;
            }
            $comparison = mb_strtolower($name);
            if (isset($seen[$comparison])) {
                $errors[$field] = 'Each template strain should appear only once.';
                continue;
            }
            $seen[$comparison] = true;
            $strains[] = $name;

            $amount = self::float($rawAmounts[$index] ?? null, true, "strainAmountsG.{$index}", $errors);
            if ($amount !== null) {
                $hasAmount = true;
                if ($amount <= 0 || $amount > self::MAX_STRAIN_AMOUNT_G) {
                    $errors["strainAmountsG.{$index}"] = 'Template strain amounts must be greater than zero and no more than 1,000,000 g.';
                    $amount = null;
                }
            }
            $amounts[] = $amount;
        }

        if ($hasAmount && in_array(null, $amounts, true)) {
            $errors['strainAmountsG'] = 'Enter an amount for every template strain or leave all amounts blank.';
        }

        return [$strains, $hasAmount ? $amounts : []];
    }

    /**
     * @param array<string, string> $errors
     * @return array{list<string>,list<float|null>}
     */
    private static function formStrains(
        mixed $rawStrains,
        mixed $rawAmounts,
        string $weightUnit,
        array &$errors,
    ): array {
        if (is_string($rawStrains)) {
            $rawStrains = preg_split('/[\r\n,]+/u', $rawStrains) ?: [];
        }
        if (!is_array($rawStrains) || !array_is_list($rawStrains)) {
            $errors['strains'] = 'Enter valid template strains.';
            $rawStrains = [];
        }
        if (self::blank($rawAmounts)) {
            $rawAmounts = [];
        } elseif (!is_array($rawAmounts) || !array_is_list($rawAmounts)) {
            if (is_scalar($rawAmounts)) {
                $rawAmounts = [$rawAmounts];
            } else {
                $errors['strain_amounts'] = 'Enter valid template strain amounts.';
                $rawAmounts = [];
            }
        }

        $rowCount = max(count($rawStrains), count($rawAmounts));
        if ($rowCount > 20) {
            $errors['strains'] = 'Use no more than 20 template strains.';
            $rowCount = 20;
        }
        $hasAmounts = false;
        foreach ($rawAmounts as $rawAmount) {
            if (!self::blank($rawAmount)) {
                $hasAmounts = true;
                break;
            }
        }

        $strains = [];
        $amounts = [];
        $seen = [];
        for ($index = 0; $index < $rowCount; $index++) {
            $rawName = $rawStrains[$index] ?? null;
            $rawAmount = $rawAmounts[$index] ?? null;
            if (self::blank($rawName) && self::blank($rawAmount)) {
                continue;
            }
            if (self::blank($rawName)) {
                $errors["strains.{$index}"] = 'Enter a strain name for this amount.';
                continue;
            }

            $name = self::text($rawName, 120, "strains.{$index}", $errors);
            if ($name === null) {
                continue;
            }
            $comparison = mb_strtolower($name);
            if (isset($seen[$comparison])) {
                $errors["strains.{$index}"] = 'Each template strain should appear only once.';
                continue;
            }
            $seen[$comparison] = true;
            $strains[] = $name;

            $amountG = null;
            if (!self::blank($rawAmount)) {
                $amount = self::formNumber($rawAmount, "strain_amounts.{$index}", $errors);
                if ($amount !== null) {
                    try {
                        $amountG = Units::weightToGrams($amount, $weightUnit);
                    } catch (InvalidArgumentException) {
                        $errors["strain_amounts.{$index}"] = 'Enter a valid strain amount.';
                    }
                }
                if ($amountG !== null && ($amountG <= 0 || $amountG > self::MAX_STRAIN_AMOUNT_G)) {
                    $errors["strain_amounts.{$index}"] = 'Enter a strain amount greater than zero and no more than 1,000,000 g.';
                    $amountG = null;
                }
            } elseif ($hasAmounts) {
                $errors["strain_amounts.{$index}"] = 'Enter an amount for every strain or leave all amounts blank.';
            }
            $amounts[] = $amountG;
        }

        return [$strains, $hasAmounts ? $amounts : []];
    }

    /** @param array<string, string> $errors */
    private static function formNumber(mixed $raw, string $field, array &$errors): ?float
    {
        if (is_bool($raw) || !is_scalar($raw)) {
            $errors[$field] = 'Enter a valid number.';
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            $value = (float) $raw;
        } else {
            $text = trim((string) $raw);
            if (substr_count($text, ',') === 1 && !str_contains($text, '.')) {
                $text = str_replace(',', '.', $text);
            }
            if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $text) !== 1) {
                $errors[$field] = 'Enter a valid number.';
                return null;
            }
            $value = (float) $text;
        }
        if (!is_finite($value)) {
            $errors[$field] = 'Enter a finite number.';
            return null;
        }
        return $value;
    }

    private static function blank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $value);
        return $trimmed === '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     * @return list<PassData>
     */
    private static function legacyPasses(array $payload, array &$errors): array
    {
        $temperature = self::float($payload['temperatureC'] ?? null, false, 'temperatureC', $errors);
        $pressure = self::float($payload['pressureBar'] ?? null, true, 'pressureBar', $errors);
        $duration = self::integer(
            $payload['pressDurationSeconds'] ?? null,
            true,
            'pressDurationSeconds',
            $errors,
        );
        $preheat = self::integer($payload['preheatSeconds'] ?? null, true, 'preheatSeconds', $errors);
        $count = self::integer($payload['numberOfPresses'] ?? 1, false, 'numberOfPresses', $errors);

        self::validatePassMeasurements($temperature, $pressure, $preheat, $duration, '', $errors);
        if ($count !== null && ($count < 1 || $count > PassFormNormalizer::MAX_PASSES)) {
            $errors['numberOfPresses'] = 'The template press count is out of range.';
        }
        if ($temperature === null || $count === null || $errors !== []) {
            return [];
        }

        $first = new PassData(
            temperatureC: $temperature,
            pressureBar: $pressure,
            preheatSeconds: $preheat,
            pressDurationSeconds: $duration,
        );
        return array_fill(0, $count, $first);
    }

    /**
     * @param array<string, string> $errors
     * @return list<PassData>
     */
    private static function passes(mixed $value, array &$errors): array
    {
        if (!is_array($value) || $value === [] || count($value) > PassFormNormalizer::MAX_PASSES) {
            $errors['passes'] = 'The template press passes are invalid.';
            return [];
        }

        $passes = [];
        foreach (array_values($value) as $index => $pass) {
            $prefix = "passes.{$index}.";
            if (!is_array($pass)) {
                $errors["passes.{$index}"] = 'The template press pass is invalid.';
                continue;
            }
            $allowed = ['temperatureC', 'pressureBar', 'preheatSeconds', 'pressDurationSeconds'];
            foreach (array_keys($pass) as $key) {
                if (!in_array($key, $allowed, true)) {
                    $errors["passes.{$index}"] = 'The template press pass contains unsupported values.';
                    break;
                }
            }

            $temperature = self::float(
                $pass['temperatureC'] ?? null,
                false,
                $prefix . 'temperatureC',
                $errors,
            );
            $pressure = self::float(
                $pass['pressureBar'] ?? null,
                true,
                $prefix . 'pressureBar',
                $errors,
            );
            $preheat = self::integer(
                $pass['preheatSeconds'] ?? null,
                true,
                $prefix . 'preheatSeconds',
                $errors,
            );
            $duration = self::integer(
                $pass['pressDurationSeconds'] ?? null,
                true,
                $prefix . 'pressDurationSeconds',
                $errors,
            );
            self::validatePassMeasurements($temperature, $pressure, $preheat, $duration, $prefix, $errors);

            $hasPassError = false;
            foreach (array_keys($errors) as $field) {
                if ($field === "passes.{$index}" || str_starts_with($field, $prefix)) {
                    $hasPassError = true;
                    break;
                }
            }
            if (!$hasPassError && $temperature !== null) {
                $passes[] = new PassData(
                    temperatureC: $temperature,
                    pressureBar: $pressure,
                    preheatSeconds: $preheat,
                    pressDurationSeconds: $duration,
                );
            }
        }
        return $passes;
    }

    /** @param array<string, string> $errors */
    private static function validatePassMeasurements(
        ?float $temperature,
        ?float $pressure,
        ?int $preheat,
        ?int $duration,
        string $prefix,
        array &$errors,
    ): void {
        if ($temperature !== null && ($temperature < -50 || $temperature > 300)) {
            $errors[$prefix . 'temperatureC'] = 'The template temperature is out of range.';
        }
        if ($pressure !== null && $pressure < 0) {
            $errors[$prefix . 'pressureBar'] = 'The template pressure cannot be negative.';
        }
        if ($preheat !== null && ($preheat < 0 || $preheat > 7200)) {
            $errors[$prefix . 'preheatSeconds'] = 'The template preheat time is out of range.';
        }
        if ($duration !== null && ($duration < 0 || $duration > 7200)) {
            $errors[$prefix . 'pressDurationSeconds'] = 'The template press duration is out of range.';
        }
    }

    /** @param array<string, string> $errors */
    private static function text(mixed $value, int $maximum, string $field, array &$errors): ?string
    {
        if (!is_string($value)) {
            $errors[$field] = 'The template value must be text.';
            return null;
        }
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $value);
        $normalized = is_string($trimmed) ? preg_replace('/\s+/u', ' ', $trimmed) : null;
        if (!is_string($normalized) || mb_strlen($normalized) < 1 || mb_strlen($normalized) > $maximum) {
            $errors[$field] = 'The template text is out of range.';
            return null;
        }
        return $normalized;
    }

    /** @param array<string, string> $errors */
    private static function float(mixed $value, bool $nullable, string $field, array &$errors): ?float
    {
        if ($value === null && $nullable) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            $errors[$field] = 'The template measurement is invalid.';
            return null;
        }
        return (float) $value;
    }

    /** @param array<string, string> $errors */
    private static function integer(mixed $value, bool $nullable, string $field, array &$errors): ?int
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_int($value)) {
            $errors[$field] = 'The template count is invalid.';
            return null;
        }
        return $value;
    }

    /**
     * @param array<string, string> $errors
     * @return list<array{position:int,brand:?string,micron:int,widthMm:float,lengthMm:float,layer:int}>
     */
    private static function bags(mixed $value, array &$errors): array
    {
        if (!is_array($value) || count($value) > 20) {
            $errors['bags'] = 'The template bag layers are invalid.';
            return [];
        }
        $bags = [];
        $layers = [];
        foreach (array_values($value) as $position => $bag) {
            if (!is_array($bag)) {
                $errors["bags.{$position}"] = 'The template bag layer is invalid.';
                continue;
            }
            $micron = $bag['micron'] ?? null;
            $width = $bag['widthMm'] ?? null;
            $length = $bag['lengthMm'] ?? null;
            $layer = $bag['layer'] ?? null;
            $brand = self::optionalText(
                $bag['brand'] ?? null,
                80,
                "bags.{$position}.brand",
                $errors,
            );
            if (!is_int($micron) || $micron < 1 || $micron > 500
                || (!is_int($width) && !is_float($width)) || !is_finite((float) $width)
                || (float) $width <= 0 || (float) $width > 1000
                || (!is_int($length) && !is_float($length)) || !is_finite((float) $length)
                || (float) $length <= 0 || (float) $length > 2000
                || !is_int($layer) || $layer < 1 || isset($layers[$layer])) {
                $errors["bags.{$position}"] = 'The template bag layer is invalid.';
                continue;
            }
            $layers[$layer] = true;
            $bags[] = [
                'position' => $position,
                'brand' => $brand,
                'micron' => $micron,
                'widthMm' => (float) $width,
                'lengthMm' => (float) $length,
                'layer' => $layer,
            ];
        }
        return $bags;
    }

    /** @param array<string, string> $errors */
    private static function optionalText(
        mixed $value,
        int $maximum,
        string $field,
        array &$errors,
    ): ?string {
        $trimmed = is_string($value) ? preg_replace('/^\s+|\s+$/u', '', $value) : null;
        if ($value === null || $trimmed === '') {
            return null;
        }
        return self::text($value, $maximum, $field, $errors);
    }
}
