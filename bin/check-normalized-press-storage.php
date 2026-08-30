<?php

declare(strict_types=1);

use RosinTracker\Domain\BatchData;
use RosinTracker\Domain\PassData;
use RosinTracker\Repository\BatchRepository;
use RosinTracker\Repository\PassRepository;

spl_autoload_register(static function (string $class): void {
    $prefix = 'RosinTracker\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function pressStorageCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$schema = file_get_contents(dirname(__DIR__) . '/migrations/001_baseline.sql');
if (!is_string($schema) || trim($schema) === '') {
    throw new RuntimeException('The consolidated baseline schema could not be loaded.');
}
$database->exec($schema);

$batchColumns = array_map(
    static fn (array $column): string => (string) $column['name'],
    $database->query('PRAGMA table_info(batches)')->fetchAll(),
);
foreach (['temperature_c', 'pressure_bar', 'preheat_seconds', 'press_duration_seconds', 'number_of_presses'] as $column) {
    pressStorageCheck(!in_array($column, $batchColumns, true), "batches still contains {$column}.");
}

$batches = new BatchRepository($database);
$passes = new PassRepository($database);
$created = $batches->create(new BatchData(
    pressedAt: '2026-08-30T12:00:00Z',
    startMaterial: 'Flower',
    startAmountG: 10.0,
    yieldAmountG: 2.0,
    temperatureC: 90.0,
    pressureBar: 70.0,
    pressCapacityTons: 10.0,
    humidityPercent: 62.0,
    pressDurationSeconds: 120,
    preheatSeconds: 45,
    numberOfPresses: 1,
    notes: null,
    sourceTemplateId: null,
    strains: ['Storage Check'],
    bags: [[
        'position' => 0,
        'brand' => null,
        'micron' => 90,
        'widthMm' => 50.0,
        'lengthMm' => 100.0,
        'layer' => 1,
    ]],
));
$batchId = (int) $created['id'];
pressStorageCheck($created['temperatureC'] === 90.0, 'Pass 1 temperature was not projected onto the batch view.');
pressStorageCheck($created['numberOfPresses'] === 1, 'The initial pass count was not projected onto the batch view.');

$second = $passes->create($batchId, new PassData(110.0, 80.0, 30, 90));
$withSecond = $batches->find($batchId);
pressStorageCheck(is_array($withSecond), 'The batch disappeared after adding a pass.');
pressStorageCheck($withSecond['temperatureC'] === 90.0, 'A later pass replaced Pass 1 in the batch view.');
pressStorageCheck($withSecond['numberOfPresses'] === 2, 'The batch view did not derive its pass count.');

$firstId = (int) $withSecond['passes'][0]['id'];
$passes->update($batchId, $firstId, new PassData(95.0, 75.0, 40, 100));
$withUpdatedFirst = $batches->find($batchId);
pressStorageCheck(
    is_array($withUpdatedFirst) && $withUpdatedFirst['temperatureC'] === 95.0,
    'Updating Pass 1 did not update the derived batch view.',
);

$passes->delete($batchId, $firstId);
$afterDelete = $batches->find($batchId);
pressStorageCheck(is_array($afterDelete), 'The batch disappeared after deleting a pass.');
pressStorageCheck($afterDelete['temperatureC'] === 110.0, 'The renumbered Pass 1 was not projected onto the batch view.');
pressStorageCheck($afterDelete['numberOfPresses'] === 1, 'The derived pass count was not updated after deletion.');
pressStorageCheck((int) $second['position'] === 2, 'The second pass fixture was not created in position 2.');

fwrite(STDOUT, "Normalized press storage check passed.\n");
