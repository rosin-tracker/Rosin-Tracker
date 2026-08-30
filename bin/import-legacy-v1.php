<?php

declare(strict_types=1);

use RosinTracker\Database;
use RosinTracker\Import\LegacyV1Importer;
use RosinTracker\Storage\PhotoStorage;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$usage = "Usage: php bin/import-legacy-v1.php --input PATH [--source-key KEY] [--dry-run|--apply]\n";
$input = null;
$sourceKey = 'legacy-v1-primary';
$mode = null;
$arguments = array_slice($argv ?? [], 1);
for ($index = 0; $index < count($arguments); $index++) {
    $argument = $arguments[$index];
    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, $usage);
        exit(0);
    }
    if ($argument === '--dry-run' || $argument === '--apply') {
        if ($mode !== null && $mode !== $argument) {
            fwrite(STDERR, "Choose either --dry-run or --apply, not both.\n" . $usage);
            exit(2);
        }
        $mode = $argument;
        continue;
    }
    if ($argument === '--input' || $argument === '--source-key') {
        $value = $arguments[++$index] ?? null;
        if (!is_string($value) || $value === '') {
            fwrite(STDERR, "Missing value for {$argument}.\n" . $usage);
            exit(2);
        }
        if ($argument === '--input') {
            $input = $value;
        } else {
            $sourceKey = $value;
        }
        continue;
    }
    if (str_starts_with($argument, '--input=')) {
        $input = substr($argument, strlen('--input='));
        continue;
    }
    if (str_starts_with($argument, '--source-key=')) {
        $sourceKey = substr($argument, strlen('--source-key='));
        continue;
    }
    fwrite(STDERR, "Unknown option: {$argument}\n" . $usage);
    exit(2);
}

if (!is_string($input) || $input === '') {
    fwrite(STDERR, "--input PATH is required.\n" . $usage);
    exit(2);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $database = (new Database($config))->connection();
    $photoStorage = new PhotoStorage($config);
    $importer = new LegacyV1Importer(
        $config,
        $database,
        $photoStorage,
    );
    $report = $importer->run($input, $sourceKey, $mode === '--apply');

    printf("Legacy v1 import %s\n", $report['mode'] === 'apply' ? 'completed.' : 'dry run completed; no records were changed.');
    printf(
        "Source: %d batches, %d passes, %d strains, %d bags, %d photos\n",
        $report['sourceBatches'],
        $report['sourcePasses'],
        $report['sourceStrains'],
        $report['sourceBags'],
        $report['sourcePhotos'],
    );
    printf(
        "Plan: %d ready, %d already imported and unchanged\n",
        $report['readyBatches'],
        $report['skippedBatches'],
    );
    if ($report['mode'] === 'apply') {
        printf(
            "Imported: %d batches and %d photos\n",
            $report['importedBatches'],
            $report['importedPhotos'],
        );
    }
    printf("Source key: %s\nExport SHA-256: %s\n", $sourceKey, $report['sourceSha256']);
    if (is_string($report['backupFilename'])) {
        printf("Verified pre-import backup: %s\n", $report['backupFilename']);
    }
    foreach ($report['warnings'] as $warning) {
        fwrite(STDOUT, 'Warning: ' . $warning . PHP_EOL);
    }
    if ($report['mode'] === 'dry-run') {
        fwrite(STDOUT, "Run the same command with --apply to create a verified backup and import.\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Legacy v1 import failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
