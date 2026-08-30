<?php

declare(strict_types=1);

namespace RosinTracker\Domain;

use DateTimeImmutable;
use DateTimeZone;
use RosinTracker\Support\Units;

/**
 * A validated batch expressed only in persistence units.
 *
 * @phpstan-type BagData array{position:int,brand?:string|null,micron:int,widthMm:float,lengthMm:float,layer:int}
 */
final readonly class BatchData
{
    /**
     * @param list<string> $strains
     * @param list<array{position:int,brand?:string|null,micron:int,widthMm:float,lengthMm:float,layer:int}> $bags
     * @param list<float|null> $strainAmountsG Canonical amounts aligned with $strains; an empty list means all unknown.
     */
    public function __construct(
        public string $pressedAt,
        public string $startMaterial,
        public float $startAmountG,
        public float $yieldAmountG,
        public float $temperatureC,
        public ?float $pressureBar,
        public ?float $pressCapacityTons,
        public ?float $humidityPercent,
        public ?int $pressDurationSeconds,
        public ?int $preheatSeconds,
        public int $numberOfPresses,
        public ?string $notes,
        public ?int $sourceTemplateId,
        public array $strains,
        public array $bags,
        public array $strainAmountsG = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toTemplatePayload(): array
    {
        return BatchTemplateData::fromBatch($this)->toPayload();
    }

    /**
     * Convert canonical storage values back into the selected display system.
     * The returned snake_case keys can be used directly to refill HTML forms.
     *
     * @return array<string, mixed>
     */
    public function toFormValues(string $unitSystem, string $timezone = 'Europe/Copenhagen'): array
    {
        $system = Units::normalizeSystem($unitSystem);
        $weightUnit = Units::weightUnitForSystem($system);
        $temperatureUnit = Units::temperatureUnitForSystem($system);
        $pressureUnit = Units::pressureUnitForSystem($system);
        $lengthUnit = Units::lengthUnitForSystem($system);
        $pressedAt = (new DateTimeImmutable($this->pressedAt))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d\TH:i:s');
        $strainAmounts = [];
        foreach ($this->strains as $position => $_strain) {
            $amountG = $this->strainAmountsG[$position] ?? null;
            $strainAmounts[] = $amountG === null
                ? ''
                : Units::gramsToWeight($amountG, $weightUnit);
        }

        return [
            'pressed_at' => $pressedAt,
            'unit_system' => $system,
            'weight_unit' => $weightUnit,
            'temperature_unit' => $temperatureUnit,
            'pressure_unit' => $pressureUnit,
            'bag_size_unit' => $lengthUnit,
            'start_material' => $this->startMaterial,
            'start_amount' => Units::gramsToWeight($this->startAmountG, $weightUnit),
            'yield_amount' => Units::gramsToWeight($this->yieldAmountG, $weightUnit),
            'temperature' => Units::celsiusToTemperature($this->temperatureC, $temperatureUnit),
            'pressure' => $this->pressureBar === null
                ? ''
                : Units::barToPressure($this->pressureBar, $pressureUnit),
            'press_capacity' => $this->pressCapacityTons ?? '',
            'humidity' => $this->humidityPercent ?? '',
            'press_duration' => $this->pressDurationSeconds ?? '',
            'preheat' => $this->preheatSeconds ?? '',
            'number_of_presses' => $this->numberOfPresses,
            'notes' => $this->notes ?? '',
            'source_template_id' => $this->sourceTemplateId ?? '',
            'strains' => $this->strains,
            'strain_amounts' => $strainAmounts,
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
}
