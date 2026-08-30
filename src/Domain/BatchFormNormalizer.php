<?php

declare(strict_types=1);

namespace RosinTracker\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RosinTracker\Support\Units;
use Throwable;

final class BatchFormNormalizer
{
    private const DISPLAY_WEIGHT_HALF_STEP = 0.00005;
    private const STRAIN_AMOUNT_EPSILON_G = 0.000001;

    /**
     * Validate submitted form values and convert them to persistence units.
     *
     * Supported form keys are documented by BatchData::toFormValues(). Camel
     * case aliases are also accepted for callers building data programmatically.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function normalize(
        array $input,
        string $unitSystem = Units::METRIC,
        string $timezone = 'Europe/Copenhagen',
        bool $requireBag = true,
    ): BatchData {
        $errors = [];
        $system = $this->unitSystem($input, $unitSystem, $errors);
        $zone = $this->timezone($timezone);

        $pressedAtMode = self::value($input, 'pressed_at_mode', 'pressedAtMode');
        if (!$this->blank($pressedAtMode)
            && (!is_scalar($pressedAtMode) || !in_array((string) $pressedAtMode, ['automatic', 'custom'], true))) {
            $errors['pressed_at_mode'] = 'Choose automatic or custom press time.';
            $pressedAtMode = null;
        }

        $weightUnit = $this->unit(
            self::value($input, 'weight_unit', 'weightUnit'),
            Units::weightUnitForSystem($system),
            'weight_unit',
            [Units::class, 'normalizeWeightUnit'],
            $errors,
        );
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
        $bagUnit = $this->unit(
            self::value($input, 'bag_size_unit', 'bagSizeUnit'),
            Units::lengthUnitForSystem($system),
            'bag_size_unit',
            [Units::class, 'normalizeLengthUnit'],
            $errors,
        );

        $pressedAtValue = self::value($input, 'pressed_at', 'pressedAt');
        if ($pressedAtMode === 'custom' && $this->blank($pressedAtValue)) {
            $errors['pressed_at'] = 'Enter the custom press date and time.';
            $pressedAt = null;
        } else {
            $pressedAt = $this->pressedAt(
                $pressedAtMode === 'automatic' ? null : $pressedAtValue,
                $zone,
                $errors,
            );
        }
        $startMaterial = $this->singleLine(
            self::value($input, 'start_material', 'startMaterial'),
            'start_material',
            1,
            120,
            $errors,
        );
        $startAmount = $this->number(
            self::value($input, 'start_amount', 'startAmount'),
            'start_amount',
            false,
            $errors,
        );
        $yieldAmount = $this->number(
            self::value($input, 'yield_amount', 'yieldAmount'),
            'yield_amount',
            false,
            $errors,
        );
        $temperature = $this->number(
            self::value($input, 'temperature'),
            'temperature',
            false,
            $errors,
        );
        $pressure = $this->number(
            self::value($input, 'pressure'),
            'pressure',
            true,
            $errors,
        );
        $pressCapacity = $this->number(
            self::value($input, 'press_capacity', 'pressCapacity', 'press_capacity_tons'),
            'press_capacity',
            true,
            $errors,
        );
        $humidity = $this->number(
            self::value($input, 'humidity', 'humidity_percent'),
            'humidity',
            true,
            $errors,
        );
        $pressDuration = $this->integer(
            self::value($input, 'press_duration', 'pressDuration', 'press_duration_seconds'),
            'press_duration',
            true,
            $errors,
        );
        $preheat = $this->integer(
            self::value($input, 'preheat', 'preheat_seconds', 'preheatingTime'),
            'preheat',
            true,
            $errors,
        );
        $numberOfPresses = $this->integer(
            self::value($input, 'number_of_presses', 'numberOfPresses'),
            'number_of_presses',
            true,
            $errors,
        ) ?? 1;
        $sourceTemplateId = $this->integer(
            self::value($input, 'source_template_id', 'sourceTemplateId'),
            'source_template_id',
            true,
            $errors,
        );
        $notes = $this->notes(self::value($input, 'notes'), $errors);
        [$strains, $strainAmountsG, $hasStrainAmounts] = $this->strains(
            self::value($input, 'strains', 'strain'),
            self::value($input, 'strain_amounts', 'strainAmounts'),
            $weightUnit,
            $errors,
        );
        $bags = $this->bags(
            self::value($input, 'bags', 'micron_bags', 'micronBags'),
            $bagUnit,
            $errors,
            $requireBag,
        );

        $startAmountG = $this->convert(
            $startAmount,
            static fn (float $value): float => Units::weightToGrams($value, $weightUnit),
            'start_amount',
            $errors,
        );
        $yieldAmountG = $this->convert(
            $yieldAmount,
            static fn (float $value): float => Units::weightToGrams($value, $weightUnit),
            'yield_amount',
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

        if ($startAmountG !== null && $startAmountG <= 0) {
            $errors['start_amount'] = 'Enter a starting amount greater than zero.';
        }
        if ($yieldAmountG !== null && $yieldAmountG < 0) {
            $errors['yield_amount'] = 'Yield cannot be negative.';
        }
        if ($startAmountG !== null && $yieldAmountG !== null && $yieldAmountG > $startAmountG) {
            $errors['yield_amount'] = 'Yield cannot be greater than the starting amount.';
        }
        if ($hasStrainAmounts
            && $startAmountG !== null
            && $startAmountG > 0
            && !in_array(null, $strainAmountsG, true)
            && abs(array_sum($strainAmountsG) - $startAmountG)
                > $this->strainAmountToleranceG($weightUnit, count($strainAmountsG))) {
            $errors['strain_amounts'] = 'Strain amounts must add up to the starting amount.';
        }
        if ($temperatureC !== null && ($temperatureC < -50 || $temperatureC > 300)) {
            $errors['temperature'] = 'Enter a temperature between -50 and 300 °C.';
        }
        if ($pressureBar !== null && $pressureBar < 0) {
            $errors['pressure'] = 'Pressure cannot be negative.';
        }
        if ($pressCapacity !== null && ($pressCapacity <= 0 || $pressCapacity > 1000)) {
            $errors['press_capacity'] = 'Enter a press capacity greater than zero and no more than 1000 tons.';
        }
        if ($humidity !== null && ($humidity < 0 || $humidity > 100)) {
            $errors['humidity'] = 'Enter humidity between 0 and 100 percent.';
        }
        if ($pressDuration !== null && ($pressDuration < 0 || $pressDuration > 7200)) {
            $errors['press_duration'] = 'Enter a press duration between 0 and 7200 seconds.';
        }
        if ($preheat !== null && ($preheat < 0 || $preheat > 7200)) {
            $errors['preheat'] = 'Enter a preheat time between 0 and 7200 seconds.';
        }
        if ($numberOfPresses < 1 || $numberOfPresses > 20) {
            $errors['number_of_presses'] = 'Enter between 1 and 20 presses.';
        }
        if ($sourceTemplateId !== null && $sourceTemplateId < 1) {
            $errors['source_template_id'] = 'The selected template is invalid.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new BatchData(
            pressedAt: (string) $pressedAt,
            startMaterial: (string) $startMaterial,
            startAmountG: (float) $startAmountG,
            yieldAmountG: (float) $yieldAmountG,
            temperatureC: (float) $temperatureC,
            pressureBar: $pressureBar,
            pressCapacityTons: $pressCapacity,
            humidityPercent: $humidity,
            pressDurationSeconds: $pressDuration,
            preheatSeconds: $preheat,
            numberOfPresses: $numberOfPresses,
            notes: $notes,
            sourceTemplateId: $sourceTemplateId,
            strains: $strains,
            bags: $bags,
            strainAmountsG: $strainAmountsG,
        );
    }

    /**
     * Normalize a New Batch form with an explicit ordered pass sequence.
     *
     * The returned BatchData deliberately has a press count of one, allowing
     * BatchRepository::create() to create Pass 1 exactly once. The caller must
     * add the remaining PassData items through PassRepository in the same
     * transaction.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function normalizeSubmission(
        array $input,
        string $unitSystem = Units::METRIC,
        string $timezone = 'Europe/Copenhagen',
        bool $requireBag = true,
    ): BatchSubmissionData {
        $errors = [];
        $passes = [];
        $batch = null;
        $explicitPasses = array_key_exists('passes', $input);

        try {
            $passes = (new PassFormNormalizer())->normalizeMany($input, $unitSystem);
        } catch (ValidationException $error) {
            $errors = array_replace($errors, $error->errors());
        }

        try {
            $batch = $this->normalize(
                $this->batchInputForSubmission($input),
                $unitSystem,
                $timezone,
                $requireBag,
            );
        } catch (ValidationException $error) {
            $batchErrors = $error->errors();
            if ($explicitPasses) {
                foreach (['temperature', 'pressure', 'preheat', 'press_duration', 'number_of_presses'] as $field) {
                    unset($batchErrors[$field]);
                }
            }
            $errors = array_replace($errors, $batchErrors);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!$batch instanceof BatchData || $passes === []) {
            throw new ValidationException(['passes' => 'Add at least one valid press pass.']);
        }

        /** @var non-empty-list<PassData> $passes */
        return new BatchSubmissionData($batch, $passes);
    }

    /**
     * Normalize a reusable settings form. Batch-specific values are replaced
     * deliberately so they can never become part of a template snapshot.
     *
     * @param array<string, mixed> $input
     */
    public function normalizeTemplate(
        array $input,
        string $unitSystem = Units::METRIC,
        string $timezone = 'Europe/Copenhagen',
        bool $requireBag = true,
    ): BatchTemplateData {
        $submission = $this->normalizeSubmission(array_replace($input, [
            'pressed_at' => null,
            'pressed_at_mode' => 'automatic',
            'start_amount' => 1,
            'yield_amount' => 0,
            'notes' => null,
            'source_template_id' => null,
            'strains' => ['Template placeholder'],
            'strain_amounts' => [],
        ]), $unitSystem, $timezone, $requireBag);

        return BatchTemplateData::fromBatch($submission->batch, $submission->passes);
    }

    /**
     * Copy Pass 1 into the legacy batch summary fields used by BatchData.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function batchInputForSubmission(array $input): array
    {
        $batchInput = $input;
        $firstPass = null;
        if (array_key_exists('passes', $input) && is_array($input['passes'])) {
            $rows = array_values($input['passes']);
            $firstPass = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];
        }

        if ($firstPass !== null) {
            $batchInput['temperature'] = self::value($firstPass, 'temperature', 'temperature_c', 'temperatureC');
            $batchInput['pressure'] = self::value($firstPass, 'pressure', 'pressure_bar', 'pressureBar');
            $batchInput['preheat'] = self::value(
                $firstPass,
                'preheat',
                'preheat_seconds',
                'preheatSeconds',
            );
            $batchInput['press_duration'] = self::value(
                $firstPass,
                'press_duration',
                'press_duration_seconds',
                'pressDuration',
                'pressDurationSeconds',
            );
        }

        unset($batchInput['passes']);
        $batchInput['number_of_presses'] = 1;
        return $batchInput;
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
    private function pressedAt(mixed $raw, DateTimeZone $timezone, array &$errors): ?string
    {
        if ($this->blank($raw)) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        if (!is_scalar($raw)) {
            $errors['pressed_at'] = 'Enter a valid press date and time.';
            return null;
        }

        $value = trim((string) $raw);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/', $value) === 1) {
                $date = new DateTimeImmutable($value);
            } else {
                $date = null;
                foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
                    $candidate = DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
                    $state = DateTimeImmutable::getLastErrors();
                    $valid = $candidate instanceof DateTimeImmutable
                        && ($state === false || ($state['warning_count'] === 0 && $state['error_count'] === 0))
                        && $candidate->format($format) === $value;
                    if ($valid) {
                        $date = $candidate;
                        break;
                    }
                }
                if (!$date instanceof DateTimeImmutable) {
                    throw new InvalidArgumentException('Invalid date.');
                }
            }

            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            $errors['pressed_at'] = 'Enter a valid press date and time.';
            return null;
        }
    }

    private function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable $error) {
            throw new InvalidArgumentException('The configured timezone is invalid.', previous: $error);
        }
    }

    /** @param array<string, string> $errors */
    private function singleLine(
        mixed $raw,
        string $field,
        int $minimum,
        int $maximum,
        array &$errors,
    ): ?string {
        if (!is_scalar($raw)) {
            $errors[$field] = 'Enter text for this field.';
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', $this->unicodeTrim((string) $raw));
        if (!is_string($value)) {
            $errors[$field] = 'The text contains invalid characters.';
            return null;
        }
        $length = mb_strlen($value);
        if ($length < $minimum || $length > $maximum) {
            $errors[$field] = "Use between {$minimum} and {$maximum} characters.";
            return null;
        }

        return $value;
    }

    /** @param array<string, string> $errors */
    private function number(mixed $raw, string $field, bool $optional, array &$errors): ?float
    {
        if ($this->blank($raw)) {
            if (!$optional) {
                $errors[$field] = 'Enter a number.';
            }
            return null;
        }
        if (is_bool($raw) || (!is_scalar($raw))) {
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

    /** @param array<string, string> $errors */
    private function integer(mixed $raw, string $field, bool $optional, array &$errors): ?int
    {
        $number = $this->number($raw, $field, $optional, $errors);
        if ($number === null) {
            return null;
        }
        if (floor($number) !== $number || $number < PHP_INT_MIN || $number > PHP_INT_MAX) {
            $errors[$field] = 'Enter a whole number.';
            return null;
        }

        return (int) $number;
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
            return $converter($value);
        } catch (InvalidArgumentException) {
            $errors[$field] = 'Enter a valid measurement.';
            return null;
        }
    }

    private function strainAmountToleranceG(string $weightUnit, int $strainCount): float
    {
        // Form values are rounded to four decimal places. Allow the maximum
        // round-trip error from every component plus the displayed total.
        $perValue = Units::weightToGrams(1.0, $weightUnit) * self::DISPLAY_WEIGHT_HALF_STEP;
        return ($perValue * (max(1, $strainCount) + 1)) + self::STRAIN_AMOUNT_EPSILON_G;
    }

    /** @param array<string, string> $errors */
    private function notes(mixed $raw, array &$errors): ?string
    {
        if ($this->blank($raw)) {
            return null;
        }
        if (!is_scalar($raw)) {
            $errors['notes'] = 'Enter valid notes.';
            return null;
        }
        $notes = $this->unicodeTrim(str_replace(["\r\n", "\r"], "\n", (string) $raw));
        if (mb_strlen($notes) > 10000) {
            $errors['notes'] = 'Use no more than 10000 characters.';
            return null;
        }

        return $notes;
    }

    /**
     * @param array<string, string> $errors
     * @return array{list<string>,list<float|null>,bool}
     */
    private function strains(
        mixed $raw,
        mixed $rawAmounts,
        string $weightUnit,
        array &$errors,
    ): array {
        if (is_string($raw)) {
            $raw = preg_split('/[\r\n,]+/u', $raw) ?: [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        if ($this->blank($rawAmounts)) {
            $rawAmounts = [];
        } elseif (!is_array($rawAmounts)) {
            if (is_scalar($rawAmounts)) {
                $rawAmounts = [$rawAmounts];
            } else {
                $errors['strain_amounts'] = 'Enter valid strain amounts.';
                $rawAmounts = [];
            }
        }

        $raw = array_values($raw);
        $rawAmounts = array_values($rawAmounts);
        $hasAmounts = false;
        foreach ($rawAmounts as $rawAmount) {
            if (!$this->blank($rawAmount)) {
                $hasAmounts = true;
                break;
            }
        }

        $strains = [];
        $strainAmountsG = [];
        $seen = [];
        $rowCount = max(count($raw), count($rawAmounts));
        for ($index = 0; $index < $rowCount; $index++) {
            $item = $raw[$index] ?? null;
            $rawAmount = $rawAmounts[$index] ?? null;
            if ($this->blank($item) && $this->blank($rawAmount)) {
                continue;
            }
            if ($this->blank($item)) {
                $errors["strains.{$index}"] = 'Enter a strain name for this amount.';
                continue;
            }
            $name = $this->singleLine($item, "strains.{$index}", 1, 120, $errors);
            if ($name === null) {
                continue;
            }
            $comparison = mb_strtolower($name);
            if (isset($seen[$comparison])) {
                $errors["strains.{$index}"] = 'Each strain should appear only once.';
                continue;
            }
            $seen[$comparison] = true;

            $amountG = null;
            if (!$this->blank($rawAmount)) {
                $amount = $this->number($rawAmount, "strain_amounts.{$index}", true, $errors);
                $amountG = $this->convert(
                    $amount,
                    static fn (float $value): float => Units::weightToGrams($value, $weightUnit),
                    "strain_amounts.{$index}",
                    $errors,
                );
                if ($amountG !== null && $amountG <= 0) {
                    $errors["strain_amounts.{$index}"] = 'Enter a strain amount greater than zero.';
                    $amountG = null;
                }
            } elseif ($hasAmounts) {
                $errors["strain_amounts.{$index}"] = 'Enter an amount for every strain or leave all amounts blank.';
            }

            $strains[] = $name;
            $strainAmountsG[] = $amountG;
        }

        if ($strains === []) {
            $errors['strains'] = 'Add at least one strain.';
        } elseif (count($strains) > 20) {
            $errors['strains'] = 'Use no more than 20 strains.';
        }

        return [$strains, $strainAmountsG, $hasAmounts];
    }

    /**
     * @param array<string, string> $errors
     * @return list<array{position:int,brand:?string,micron:int,widthMm:float,lengthMm:float,layer:int}>
     */
    private function bags(
        mixed $raw,
        string $fallbackUnit,
        array &$errors,
        bool $required = true,
    ): array
    {
        if ($this->blank($raw)) {
            if ($required) {
                $errors['bags'] = 'Add at least one bag to this batch.';
            }
            return [];
        }
        if (!is_array($raw)) {
            $errors['bags'] = 'Bag layers are invalid.';
            return [];
        }
        if (count($raw) > 20) {
            $errors['bags'] = 'Use no more than 20 bag layers.';
        }

        $bags = [];
        $seenLayers = [];
        foreach (array_values($raw) as $index => $bag) {
            if (!is_array($bag)) {
                $errors["bags.{$index}"] = 'This bag layer is invalid.';
                continue;
            }
            if ($this->bagIsBlank($bag)) {
                continue;
            }

            $brand = null;
            if (!$this->blank($bag['brand'] ?? null)) {
                $brand = $this->singleLine(
                    $bag['brand'],
                    "bags.{$index}.brand",
                    1,
                    80,
                    $errors,
                );
            }
            $micron = $this->integer($bag['micron'] ?? null, "bags.{$index}.micron", false, $errors);
            $layer = $this->integer($bag['layer'] ?? ($index + 1), "bags.{$index}.layer", false, $errors);
            [$widthRaw, $lengthRaw] = $this->bagDimensions($bag);
            $width = $this->number($widthRaw, "bags.{$index}.width", false, $errors);
            $length = $this->number($lengthRaw, "bags.{$index}.length", false, $errors);
            $unit = $this->unit(
                $bag['unit'] ?? $bag['size_unit'] ?? $fallbackUnit,
                $fallbackUnit,
                "bags.{$index}.unit",
                [Units::class, 'normalizeLengthUnit'],
                $errors,
            );
            $widthMm = $this->convert(
                $width,
                static fn (float $value): float => Units::lengthToMillimetres($value, $unit),
                "bags.{$index}.width",
                $errors,
            );
            $lengthMm = $this->convert(
                $length,
                static fn (float $value): float => Units::lengthToMillimetres($value, $unit),
                "bags.{$index}.length",
                $errors,
            );

            if ($micron !== null && ($micron < 1 || $micron > 500)) {
                $errors["bags.{$index}.micron"] = 'Enter a micron size between 1 and 500.';
            }
            if ($widthMm !== null && ($widthMm <= 0 || $widthMm > 1000)) {
                $errors["bags.{$index}.width"] = 'Bag width must be greater than 0 and no more than 1000 mm.';
            }
            if ($lengthMm !== null && ($lengthMm <= 0 || $lengthMm > 2000)) {
                $errors["bags.{$index}.length"] = 'Bag length must be greater than 0 and no more than 2000 mm.';
            }
            if ($layer !== null && $layer < 1) {
                $errors["bags.{$index}.layer"] = 'Layer numbers start at 1.';
            } elseif ($layer !== null && isset($seenLayers[$layer])) {
                $errors["bags.{$index}.layer"] = 'Each layer number must be unique.';
            } elseif ($layer !== null) {
                $seenLayers[$layer] = true;
            }

            if ($micron !== null && $widthMm !== null && $lengthMm !== null && $layer !== null) {
                $bags[] = [
                    'position' => count($bags),
                    'brand' => $brand,
                    'micron' => $micron,
                    'widthMm' => $widthMm,
                    'lengthMm' => $lengthMm,
                    'layer' => $layer,
                ];
            }
        }

        if ($required && $bags === [] && !isset($errors['bags'])) {
            $errors['bags'] = 'Add at least one complete bag layer.';
        }
        return $bags;
    }

    /** @param array<string, mixed> $bag */
    private function bagIsBlank(array $bag): bool
    {
        foreach (['brand', 'micron', 'width', 'length', 'height', 'size', 'layer'] as $key) {
            if (array_key_exists($key, $bag) && !$this->blank($bag[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $bag @return array{mixed, mixed} */
    private function bagDimensions(array $bag): array
    {
        $width = $bag['width'] ?? $bag['width_mm'] ?? $bag['widthMm'] ?? null;
        $length = $bag['length'] ?? $bag['height'] ?? $bag['length_mm'] ?? $bag['lengthMm'] ?? null;
        if ((!$this->blank($width) || !$this->blank($length)) || $this->blank($bag['size'] ?? null)) {
            return [$width, $length];
        }

        $size = trim((string) $bag['size']);
        if (preg_match('/^([+-]?(?:\d+(?:[.,]\d*)?|[.,]\d+))\s*[x×]\s*([+-]?(?:\d+(?:[.,]\d*)?|[.,]\d+))$/iu', $size, $match) !== 1) {
            return [null, null];
        }

        return [$match[1], $match[2]];
    }

    private function unicodeTrim(string $value): string
    {
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $value);
        return is_string($trimmed) ? $trimmed : '';
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && $this->unicodeTrim($value) === '');
    }

    /** @param array<string, mixed> $input */
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
