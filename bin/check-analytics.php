<?php

declare(strict_types=1);

use RosinTracker\Repository\AnalyticsRepository;
use RosinTracker\Repository\DashboardRepository;

require_once dirname(__DIR__) . '/src/Repository/AnalyticsRepository.php';
require_once dirname(__DIR__) . '/src/Repository/DashboardRepository.php';

function analyticsCheckSame(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }
}

function analyticsCheckNear(float $actual, float $expected, string $message): void
{
    if (abs($actual - $expected) > 0.001) {
        throw new RuntimeException("{$message}; expected {$expected}, got {$actual}");
    }
}

/** @param list<array<string, mixed>> $rows */
function analyticsCheckRow(array $rows, string $key, string $value): array
{
    foreach ($rows as $row) {
        if (strcasecmp((string) ($row[$key] ?? ''), $value) === 0) {
            return $row;
        }
    }
    throw new RuntimeException("Missing {$key} row {$value}.");
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(
    'CREATE TABLE batches ('
    . 'id INTEGER PRIMARY KEY, pressed_at TEXT NOT NULL, start_material TEXT NOT NULL, '
    . 'start_amount_g REAL NOT NULL, yield_amount_g REAL NOT NULL'
    . '); '
    . 'CREATE TABLE batch_strains ('
    . 'id INTEGER PRIMARY KEY, batch_id INTEGER NOT NULL, position INTEGER NOT NULL, name TEXT NOT NULL'
    . '); '
    . 'CREATE TABLE batch_passes ('
    . 'id INTEGER PRIMARY KEY, batch_id INTEGER NOT NULL, position INTEGER NOT NULL, temperature_c REAL NOT NULL'
    . ');'
);

$insertBatch = $database->prepare(
    'INSERT INTO batches '
    . '(id, pressed_at, start_material, start_amount_g, yield_amount_g) '
    . 'VALUES (:id, :pressed_at, :material, :input, :output)'
);
$insertStrain = $database->prepare(
    'INSERT INTO batch_strains (id, batch_id, position, name) VALUES (:id, :batch_id, :position, :name)'
);
$insertPass = $database->prepare(
    'INSERT INTO batch_passes (id, batch_id, position, temperature_c) '
    . 'VALUES (:id, :batch_id, :position, :temperature)'
);

$batches = [
    [1, '2026-01-01T12:00:00Z', 'flower', 10.0, 1.0, 2, ['Alpha'], [80.0, 130.0]],
    [2, '2026-01-02T12:00:00Z', 'Flower', 20.0, 4.0, 1, ['Alpha', 'Beta'], [90.0]],
    [3, '2026-01-05T12:00:00Z', 'hash', 10.0, 0.0, 1, ['Beta'], [70.0]],
    [4, '2026-01-04T12:00:00Z', 'Hash', 30.0, 9.0, 1, ['ALPHA'], [82.0]],
    // The latest ID deliberately has an old custom date. Sequence charts use ID, not date.
    [5, '2025-12-01T12:00:00Z', 'FLOWER', 40.0, 4.0, 2, ['Beta', 'Alpha'], [95.0, 115.0]],
];
$strainId = 1;
$passId = 1;
foreach ($batches as [$id, $pressedAt, $material, $input, $output, $passes, $strains, $temperatures]) {
    $insertBatch->execute([
        'id' => $id,
        'pressed_at' => $pressedAt,
        'material' => $material,
        'input' => $input,
        'output' => $output,
    ]);
    foreach ($strains as $position => $strain) {
        $insertStrain->execute([
            'id' => $strainId++,
            'batch_id' => $id,
            'position' => $position,
            'name' => $strain,
        ]);
    }
    foreach ($temperatures as $position => $temperature) {
        $insertPass->execute([
            'id' => $passId++,
            'batch_id' => $id,
            'position' => $position + 1,
            'temperature' => $temperature,
        ]);
    }
}

$analytics = new AnalyticsRepository($database);
$dashboard = new DashboardRepository($database);

analyticsCheckSame($analytics->materialOptions(), ['flower', 'hash'], 'Materials were not normalized by first spelling');
analyticsCheckSame($analytics->strainOptions('FLOWER'), ['Alpha', 'Beta'], 'Strain options ignored material normalization');

$summary = $analytics->summary();
analyticsCheckSame($summary['totalBatches'], 5, 'Child rows multiplied or zero yield removed from the summary');
analyticsCheckNear($summary['averageYield'], 14.0, 'Average yield is wrong');
analyticsCheckNear($summary['medianYield'], 10.0, 'Median yield is wrong');
analyticsCheckNear($summary['yieldConsistency'], 10.0, 'Yield consistency MAD is wrong');
analyticsCheckNear($summary['overallYield'], 16.36, 'Weighted overall yield is wrong');
analyticsCheckNear($summary['bestYield'], 30.0, 'Best yield is wrong');
analyticsCheckNear($summary['totalInputG'], 110.0, 'Total input is wrong');
analyticsCheckNear($summary['totalYieldG'], 18.0, 'Total output is wrong');
$smallSummary = $analytics->summary('hash');
analyticsCheckSame($smallSummary['totalBatches'], 2, 'Small-sample consistency filter is wrong');
analyticsCheckNear($smallSummary['yieldConsistency'], 15.0, 'MAD was not returned for a small sample');

$flower = analyticsCheckRow($analytics->materialPerformance(), 'material', 'flower');
analyticsCheckSame($flower['batchCount'], 3, 'Material grouping was case-sensitive or multiplied batches');
analyticsCheckNear((float) $flower['medianYield'], 10.0, 'Material median is wrong');
analyticsCheckNear((float) $flower['overallYield'], 12.86, 'Material weighted yield is wrong');

$alpha = analyticsCheckRow($analytics->strainPerformance(), 'strain', 'alpha');
analyticsCheckSame($alpha['batchCount'], 2, 'Blend batches were incorrectly credited to a single strain');
analyticsCheckNear((float) $alpha['averageYield'], 20.0, 'Single-strain average is wrong');
analyticsCheckNear((float) $alpha['overallYield'], 25.0, 'Single-strain weighted yield is wrong');

$blends = $analytics->blendPerformance('flower');
analyticsCheckSame(count($blends), 1, 'Equivalent blend orders were not merged');
$alphaBeta = analyticsCheckRow($blends, 'blend', 'Alpha + Beta');
analyticsCheckSame($alphaBeta['batchCount'], 2, 'Equivalent blend batch count is wrong');
analyticsCheckNear((float) $alphaBeta['averageYield'], 15.0, 'Blend average is wrong');
analyticsCheckNear((float) $alphaBeta['medianYield'], 15.0, 'Blend median is wrong');
analyticsCheckNear((float) $alphaBeta['overallYield'], 13.33, 'Blend weighted yield is wrong');

$alphaSummary = $analytics->summary(null, 'alpha');
analyticsCheckSame($alphaSummary['totalBatches'], 4, 'Strain filter did not include matching blends exactly once');
analyticsCheckNear($alphaSummary['medianYield'], 15.0, 'Filtered median is wrong');
analyticsCheckNear($alphaSummary['overallYield'], 18.0, 'Filtered weighted yield is wrong');

$temperature = $analytics->temperatureYield();
$batchOnePoint = analyticsCheckRow($temperature['points'], 'id', '1');
analyticsCheckNear((float) $batchOnePoint['temperatureC'], 80.0, 'Temperature did not use Pass 1');
analyticsCheckSame($batchOnePoint['passCount'], 2, 'Pass count is wrong');
$eightyBand = analyticsCheckRow($temperature['bands'], 'temperatureC', '80');
analyticsCheckSame($eightyBand['batchCount'], 2, 'Temperature-band count is wrong');
analyticsCheckNear((float) $eightyBand['averageYield'], 20.0, 'Temperature-band average is wrong');
analyticsCheckNear((float) $eightyBand['medianYield'], 20.0, 'Temperature-band median is wrong');

$sequence = $analytics->yieldSequence(null, null, 3);
analyticsCheckSame(array_column($sequence, 'id'), [3, 4, 5], 'Yield sequence was ordered by date instead of batch number');
$comparison = $analytics->batchComparison('flower', null, 10);
analyticsCheckSame(array_column($comparison, 'id'), [2, 1, 5], 'Batch comparison filter or date order is wrong');
$recent = $dashboard->recentBatches(10, 'FLOWER');
analyticsCheckSame(array_map('intval', array_column($recent, 'id')), [2, 1, 5], 'Dashboard material filter is wrong');

// Add a newer 20% flower/Alpha batch to verify filtered highest-yield tie-breaking.
$insertBatch->execute([
    'id' => 6,
    'pressed_at' => '2026-01-06T12:00:00Z',
    'material' => 'Flower',
    'input' => 10.0,
    'output' => 2.0,
]);
$insertStrain->execute([
    'id' => $strainId++,
    'batch_id' => 6,
    'position' => 0,
    'name' => 'Alpha',
]);
$insertPass->execute([
    'id' => $passId++,
    'batch_id' => 6,
    'position' => 1,
    'temperature' => 88.0,
]);
$highestFlowerAlpha = $analytics->highestYieldBatch('flower', 'alpha');
analyticsCheckSame($highestFlowerAlpha['id'] ?? null, 6, 'Highest filtered yield did not prefer the highest tied batch ID');
analyticsCheckSame($highestFlowerAlpha['strains'] ?? null, 'Alpha', 'Highest-yield batch omitted strain details');
analyticsCheckNear((float) ($highestFlowerAlpha['startAmountG'] ?? 0.0), 10.0, 'Highest-yield batch omitted input details');
analyticsCheckNear((float) ($highestFlowerAlpha['temperatureC'] ?? 0.0), 88.0, 'Highest-yield batch omitted Pass 1 details');

// Exceed the former default of 25 rows. With no limit, the sequence must retain
// the oldest flower observation as well as every newer hash observation.
for ($id = 7; $id <= 30; $id++) {
    $insertBatch->execute([
        'id' => $id,
        'pressed_at' => sprintf('2026-02-%02dT12:00:00Z', $id - 6),
        'material' => 'Hash',
        'input' => 10.0,
        'output' => 0.5,
    ]);
    $insertPass->execute([
        'id' => $passId++,
        'batch_id' => $id,
        'position' => 1,
        'temperature' => 85.0,
    ]);
}
$unlimitedSequence = $analytics->yieldSequence();
analyticsCheckSame(count($unlimitedSequence), 30, 'Yield sequence still applied a default row limit');
analyticsCheckSame($unlimitedSequence[0]['id'] ?? null, 1, 'Unlimited sequence omitted the oldest batch');
analyticsCheckSame($unlimitedSequence[0]['material'] ?? null, 'flower', 'Unlimited sequence omitted the oldest material');
analyticsCheckSame($unlimitedSequence[29]['id'] ?? null, 30, 'Unlimited sequence omitted the newest batch');
analyticsCheckSame(
    array_column($analytics->yieldSequence(null, null, 3), 'id'),
    [28, 29, 30],
    'Positive yield-sequence limit compatibility is broken',
);

fwrite(STDOUT, "Analytics repository check passed.\n");
