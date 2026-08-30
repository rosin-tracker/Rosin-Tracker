<?php

declare(strict_types=1);

namespace RosinTracker\Domain;

use RosinTracker\Support\Units;

/** A single pass through the press, expressed only in persistence units. */
final readonly class PassData
{
    public function __construct(
        public float $temperatureC,
        public ?float $pressureBar,
        public ?int $preheatSeconds,
        public ?int $pressDurationSeconds,
    ) {
    }

    public static function fromBatch(BatchData $batch): self
    {
        return new self(
            temperatureC: $batch->temperatureC,
            pressureBar: $batch->pressureBar,
            preheatSeconds: $batch->preheatSeconds,
            pressDurationSeconds: $batch->pressDurationSeconds,
        );
    }

    /** @return array{temperatureC:float,pressureBar:?float,preheatSeconds:?int,pressDurationSeconds:?int} */
    public function toTemplatePayload(): array
    {
        return [
            'temperatureC' => $this->temperatureC,
            'pressureBar' => $this->pressureBar,
            'preheatSeconds' => $this->preheatSeconds,
            'pressDurationSeconds' => $this->pressDurationSeconds,
        ];
    }

    /** @return array<string, int|float|string> */
    public function toFormValues(string $unitSystem): array
    {
        $system = Units::normalizeSystem($unitSystem);
        $temperatureUnit = Units::temperatureUnitForSystem($system);
        $pressureUnit = Units::pressureUnitForSystem($system);

        return [
            'unit_system' => $system,
            'temperature_unit' => $temperatureUnit,
            'pressure_unit' => $pressureUnit,
            'temperature' => Units::celsiusToTemperature($this->temperatureC, $temperatureUnit),
            'pressure' => $this->pressureBar === null
                ? ''
                : Units::barToPressure($this->pressureBar, $pressureUnit),
            'preheat' => $this->preheatSeconds ?? '',
            'press_duration' => $this->pressDurationSeconds ?? '',
        ];
    }
}
