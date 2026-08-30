<?php

declare(strict_types=1);

namespace RosinTracker\Import;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RosinTracker\Config;
use RosinTracker\Domain\BatchData;
use RosinTracker\Repository\BatchRepository;
use RosinTracker\Repository\PhotoRepository;
use RosinTracker\Storage\BackupService;
use RosinTracker\Storage\PhotoStorage;
use RuntimeException;
use Throwable;

/** One-time, additive importer for the legacy Rosin Tracker 2.0 JSON export. */
final class LegacyV1Importer
{
    private const MAPPING_VERSION = 'legacy-v1-map-1-g-c-psi-mm-tons-zero-null-identical-passes';
    private const PSI_TO_BAR = 0.0689475729317831;
    private const MAXIMUM_SOURCE_BYTES = 512 * 1024 * 1024;

    /** @var list<string> */
    private array $temporaryPhotoPaths = [];

    public function __construct(
        private readonly Config $config,
        private readonly PDO $database,
        private readonly PhotoStorage $photoStorage,
    ) {
    }

    /**
     * @return array{
     *   mode:string,sourceSha256:string,sourceBatches:int,sourcePasses:int,
     *   sourceStrains:int,sourceBags:int,sourcePhotos:int,readyBatches:int,
     *   skippedBatches:int,importedBatches:int,importedPhotos:int,
     *   backupFilename:?string,warnings:list<string>
     * }
     */
    public function run(string $inputPath, string $sourceKey, bool $apply): array
    {
        $this->assertSourceKey($sourceKey);
        try {
            [$sourceSha256, $plans, $warnings, $totals] = $this->buildPlan($inputPath);
            [$ready, $skipped] = $this->partitionExisting($sourceKey, $plans);

            $result = [
                'mode' => $apply ? 'apply' : 'dry-run',
                'sourceSha256' => $sourceSha256,
                'sourceBatches' => $totals['batches'],
                'sourcePasses' => $totals['passes'],
                'sourceStrains' => $totals['strains'],
                'sourceBags' => $totals['bags'],
                'sourcePhotos' => $totals['photos'],
                'readyBatches' => count($ready),
                'skippedBatches' => count($skipped),
                'importedBatches' => 0,
                'importedPhotos' => 0,
                'backupFilename' => null,
                'warnings' => $warnings,
            ];
            if (!$apply || $ready === []) {
                return $result;
            }

            $backup = (new BackupService($this->config, $this->database))->create();
            $result['backupFilename'] = $backup['filename'];
            [$importedBatches, $importedPhotos] = $this->apply($sourceKey, $ready, $plans, $totals);
            $result['importedBatches'] = $importedBatches;
            $result['importedPhotos'] = $importedPhotos;
            return $result;
        } finally {
            $this->removeTemporaryPhotos();
        }
    }

    /**
     * @return array{
     *   string,
     *   list<array{legacyId:int,rowHash:string,batch:BatchData,photos:list<array<string,mixed>>,syntheticMaterial:bool}>,
     *   list<string>,
     *   array{batches:int,passes:int,strains:int,bags:int,photos:int}
     * }
     */
    private function buildPlan(string $inputPath): array
    {
        if ($inputPath === '' || !is_file($inputPath) || is_link($inputPath)) {
            throw new RuntimeException('The legacy export is missing or unsafe.');
        }
        $size = filesize($inputPath);
        if ($size === false || $size < 1 || $size > self::MAXIMUM_SOURCE_BYTES) {
            throw new RuntimeException('The legacy export has an invalid size.');
        }
        $json = file_get_contents($inputPath);
        if (!is_string($json)) {
            throw new RuntimeException('The legacy export could not be read.');
        }
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('The legacy export is not valid JSON.', previous: $error);
        }
        if (!is_array($document)) {
            throw new RuntimeException('The legacy export root must be an object.');
        }
        $metadata = $document['metadata'] ?? null;
        $data = $document['data'] ?? null;
        if (!is_array($metadata) || !is_array($data)
            || (string) ($metadata['application'] ?? '') !== 'Rosin Tracker'
            || (string) ($metadata['version'] ?? '') !== '2.0') {
            throw new RuntimeException('This is not a supported Rosin Tracker 2.0 legacy export.');
        }
        $rows = $data['rosinPresses'] ?? null;
        $curingLogs = $data['curingLogs'] ?? null;
        if (!is_array($rows) || !is_array($curingLogs)) {
            throw new RuntimeException('The legacy export is missing its record arrays.');
        }
        if ($curingLogs !== []) {
            throw new RuntimeException(
                'The export contains curing logs, which this edition intentionally does not import.'
            );
        }
        $reported = $metadata['recordCounts'] ?? null;
        if (is_array($reported)) {
            if ((int) ($reported['rosinPresses'] ?? -1) !== count($rows)
                || (int) ($reported['curingLogs'] ?? -1) !== count($curingLogs)) {
                throw new RuntimeException('The legacy export record counts do not match its contents.');
            }
        }

        $plans = [];
        $seenIds = [];
        $blankMaterials = 0;
        $copiedPassRecords = 0;
        $pressureConversions = 0;
        $totals = ['batches' => count($rows), 'passes' => 0, 'strains' => 0, 'bags' => 0, 'photos' => 0];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Legacy record at position ' . ($index + 1) . ' is not an object.');
            }
            $legacyId = $this->positiveInteger($row['id'] ?? null, 'record ID', $index + 1);
            if (isset($seenIds[$legacyId])) {
                throw new RuntimeException('Legacy record ID ' . $legacyId . ' appears more than once.');
            }
            $seenIds[$legacyId] = true;
            $context = 'Legacy record #' . $legacyId;

            $pressedAt = $this->timestamp($row['pressDate'] ?? null, $context);
            $material = trim($this->stringValue($row['startMaterial'] ?? '', $context . ' material'));
            $syntheticMaterial = $material === '';
            if ($syntheticMaterial) {
                $material = 'Not recorded';
                $blankMaterials++;
            }
            if (mb_strlen($material) > 120) {
                throw new RuntimeException($context . ' has a material name longer than 120 characters.');
            }
            $startAmount = $this->finiteNumber($row['startAmount'] ?? null, $context . ' start amount');
            $yieldAmount = $this->finiteNumber($row['yieldAmount'] ?? null, $context . ' yield amount');
            if ($startAmount <= 0 || $yieldAmount < 0 || $yieldAmount > $startAmount) {
                throw new RuntimeException($context . ' has invalid start or yield weights.');
            }
            $storedYield = $this->finiteNumber($row['yieldPercentage'] ?? null, $context . ' yield percentage');
            $computedYield = ($yieldAmount / $startAmount) * 100.0;
            if (abs($storedYield - $computedYield) > 0.02) {
                throw new RuntimeException($context . ' has an inconsistent saved yield percentage.');
            }

            $temperature = $this->finiteNumber($row['temperature'] ?? null, $context . ' temperature');
            if ($temperature < -50 || $temperature > 300) {
                throw new RuntimeException($context . ' has an unsupported Celsius temperature.');
            }
            $legacyPressure = $this->finiteNumber($row['pressure'] ?? 0, $context . ' pressure');
            if ($legacyPressure < 0) {
                throw new RuntimeException($context . ' has a negative pressure.');
            }
            $pressureBar = $legacyPressure === 0.0 ? null : $legacyPressure * self::PSI_TO_BAR;
            if ($pressureBar !== null) {
                $pressureConversions++;
            }
            $pressCapacity = $this->nullableFiniteNumber($row['pressSize'] ?? null, $context . ' press size');
            if ($pressCapacity !== null && $pressCapacity <= 0) {
                $pressCapacity = null;
            }
            $humidity = $this->nullableFiniteNumber($row['humidity'] ?? null, $context . ' humidity');
            if ($humidity !== null && ($humidity < 0 || $humidity > 100)) {
                throw new RuntimeException($context . ' has an invalid humidity percentage.');
            }
            if ($humidity === 0.0) {
                $humidity = null;
            }
            $duration = $this->nullableNonNegativeInteger(
                $row['pressDuration'] ?? null,
                $context . ' press duration',
                7200,
            );
            $preheat = $this->nullableNonNegativeInteger(
                $row['preheatingTime'] ?? null,
                $context . ' preheat duration',
                7200,
            );
            $passes = $this->positiveInteger($row['numberOfPresses'] ?? 1, 'pass count', $legacyId);
            if ($passes > 20) {
                throw new RuntimeException($context . ' has more than 20 passes.');
            }
            if ($passes > 1) {
                $copiedPassRecords++;
            }

            $strains = $this->strains($row['strain'] ?? null, $context);
            $bags = $this->bags($row['micronBags'] ?? [], $context);
            $photos = $this->photos($row['pictures'] ?? [], $legacyId);
            $notes = $row['notes'] ?? null;
            if ($notes !== null && !is_string($notes)) {
                throw new RuntimeException($context . ' notes are not text.');
            }

            try {
                $canonicalRow = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException($context . ' could not be fingerprinted.', previous: $error);
            }
            $rowHash = hash('sha256', self::MAPPING_VERSION . "\0" . $canonicalRow);
            $plans[] = [
                'legacyId' => $legacyId,
                'rowHash' => $rowHash,
                'batch' => new BatchData(
                    pressedAt: $pressedAt,
                    startMaterial: $material,
                    startAmountG: $startAmount,
                    yieldAmountG: $yieldAmount,
                    temperatureC: $temperature,
                    pressureBar: $pressureBar,
                    pressCapacityTons: $pressCapacity,
                    humidityPercent: $humidity,
                    pressDurationSeconds: $duration,
                    preheatSeconds: $preheat,
                    numberOfPresses: $passes,
                    notes: $notes,
                    sourceTemplateId: null,
                    strains: $strains,
                    bags: $bags,
                ),
                'photos' => $photos,
                'syntheticMaterial' => $syntheticMaterial,
            ];
            $totals['passes'] += $passes;
            $totals['strains'] += count($strains);
            $totals['bags'] += count($bags);
            $totals['photos'] += count($photos);
        }
        usort($plans, static fn (array $left, array $right): int => $left['legacyId'] <=> $right['legacyId']);

        $warnings = [
            'Exported ISO timestamps are preserved as UTC instants.',
            'Legacy pressure values are interpreted as PSI and converted to canonical bar; zero becomes not recorded.',
            'Legacy humidity zero becomes not recorded.',
        ];
        if ($blankMaterials > 0) {
            $warnings[] = $blankMaterials . ' record(s) without a material will use “Not recorded”.';
        }
        if ($copiedPassRecords > 0) {
            $warnings[] = $copiedPassRecords
                . ' record(s) with multiple presses will use identical ordered passes because v1 stored one settings set.';
        }
        if ($pressureConversions === 0) {
            $warnings[] = 'No non-zero legacy pressure values were present.';
        }
        return [hash('sha256', $json), $plans, $warnings, $totals];
    }

    /**
     * @param list<array{legacyId:int,rowHash:string,batch:BatchData,photos:list<array<string,mixed>>,syntheticMaterial:bool}> $plans
     * @return array{list<array<string,mixed>>,list<array<string,mixed>>}
     */
    private function partitionExisting(string $sourceKey, array $plans): array
    {
        $existingStatement = $this->database->prepare(
            'SELECT legacy_record_id, source_row_sha256 FROM legacy_v1_imports '
            . 'WHERE source_key = :source_key ORDER BY legacy_record_id'
        );
        $existingStatement->execute(['source_key' => $sourceKey]);
        $existing = [];
        foreach ($existingStatement->fetchAll() as $row) {
            $existing[(int) $row['legacy_record_id']] = (string) $row['source_row_sha256'];
        }
        $sourceIds = array_fill_keys(array_map(static fn (array $plan): int => $plan['legacyId'], $plans), true);
        foreach (array_keys($existing) as $legacyId) {
            if (!isset($sourceIds[$legacyId])) {
                throw new RuntimeException(
                    'Source key ' . $sourceKey . ' was already used, but the new export omits legacy record #'
                    . $legacyId . '.'
                );
            }
        }

        $ready = [];
        $skipped = [];
        foreach ($plans as $plan) {
            $legacyId = $plan['legacyId'];
            if (!isset($existing[$legacyId])) {
                $ready[] = $plan;
                continue;
            }
            if (!hash_equals($existing[$legacyId], $plan['rowHash'])) {
                throw new RuntimeException(
                    'Legacy record #' . $legacyId . ' differs from the version already imported under this source key.'
                );
            }
            $skipped[] = $plan;
        }
        return [$ready, $skipped];
    }

    /**
     * @param list<array{legacyId:int,rowHash:string,batch:BatchData,photos:list<array<string,mixed>>,syntheticMaterial:bool}> $ready
     * @param list<array{legacyId:int,rowHash:string,batch:BatchData,photos:list<array<string,mixed>>,syntheticMaterial:bool}> $allPlans
     * @param array{batches:int,passes:int,strains:int,bags:int,photos:int} $totals
     * @return array{int,int}
     */
    private function apply(string $sourceKey, array $ready, array $allPlans, array $totals): array
    {
        $batchRepository = new BatchRepository($this->database);
        $photoRepository = new PhotoRepository($this->database);
        $storedNames = [];
        $importedPhotos = 0;
        $this->database->beginTransaction();
        try {
            $provenance = $this->database->prepare(
                'INSERT INTO legacy_v1_imports '
                . '(source_key, legacy_record_id, source_row_sha256, batch_id, imported_at) '
                . 'VALUES (:source_key, :legacy_record_id, :source_row_sha256, :batch_id, :imported_at)'
            );
            foreach ($ready as $plan) {
                $batch = $batchRepository->create($plan['batch']);
                $batchId = (int) $batch['id'];
                foreach ($plan['photos'] as $position => $photo) {
                    $stored = $this->photoStorage->store($batchId, $photo['upload']);
                    $storedNames[] = $stored['storage_name'];
                    $photoRepository->add($batchId, $position, $stored);
                    $importedPhotos++;
                }
                $provenance->execute([
                    'source_key' => $sourceKey,
                    'legacy_record_id' => $plan['legacyId'],
                    'source_row_sha256' => $plan['rowHash'],
                    'batch_id' => $batchId,
                    'imported_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            }
            $this->addPresets($allPlans);
            $this->verifyImportedRows($sourceKey, $totals);
            if ($this->database->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('The imported database failed its integrity check.');
            }
            if ($this->database->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('The imported database contains a foreign-key violation.');
            }
            $paths = $this->database->prepare(
                'SELECT p.storage_name FROM batch_photos AS p '
                . 'JOIN legacy_v1_imports AS i ON i.batch_id = p.batch_id WHERE i.source_key = :source_key'
            );
            $paths->execute(['source_key' => $sourceKey]);
            foreach ($paths->fetchAll(PDO::FETCH_COLUMN) as $storageName) {
                if ($this->photoStorage->absolutePath((string) $storageName) === null) {
                    throw new RuntimeException('An imported photograph is missing from private storage.');
                }
            }
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            foreach (array_reverse($storedNames) as $storageName) {
                $this->photoStorage->delete($storageName);
            }
            throw $error;
        }

        return [count($ready), $importedPhotos];
    }

    /** @param list<array<string,mixed>> $plans */
    private function addPresets(array $plans): void
    {
        $insert = $this->database->prepare(
            'INSERT OR IGNORE INTO preset_options '
            . '(field_key, label, value_json, sort_order, created_at, updated_at) '
            . 'VALUES (:field_key, :label, :value_json, :sort_order, :created_at, :updated_at)'
        );
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $sort = 5000;
        $seen = [];
        $add = static function (string $field, string $label, mixed $value) use (&$seen, &$sort, $insert, $now): void {
            $key = $field . "\0" . mb_strtolower($label);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $insert->execute([
                'field_key' => $field,
                'label' => $label,
                'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'sort_order' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };
        foreach ($plans as $plan) {
            /** @var BatchData $batch */
            $batch = $plan['batch'];
            if (!$plan['syntheticMaterial']) {
                $add('start_material', $batch->startMaterial, null);
            }
            foreach ($batch->strains as $strain) {
                $add('strain', $strain, null);
            }
            foreach ($batch->bags as $bag) {
                $label = sprintf('%g × %g mm · %d μm', $bag['widthMm'], $bag['lengthMm'], $bag['micron']);
                $add('bag', $label, [
                    'micron' => $bag['micron'],
                    'widthMm' => $bag['widthMm'],
                    'lengthMm' => $bag['lengthMm'],
                ]);
            }
        }
    }

    /** @param array{batches:int,passes:int,strains:int,bags:int,photos:int} $totals */
    private function verifyImportedRows(string $sourceKey, array $totals): void
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(DISTINCT i.legacy_record_id) AS batches, '
            . 'COUNT(DISTINCT p.id) AS passes, COUNT(DISTINCT s.id) AS strains, '
            . 'COUNT(DISTINCT g.id) AS bags, COUNT(DISTINCT ph.id) AS photos '
            . 'FROM legacy_v1_imports AS i '
            . 'LEFT JOIN batch_passes AS p ON p.batch_id = i.batch_id '
            . 'LEFT JOIN batch_strains AS s ON s.batch_id = i.batch_id '
            . 'LEFT JOIN batch_bags AS g ON g.batch_id = i.batch_id '
            . 'LEFT JOIN batch_photos AS ph ON ph.batch_id = i.batch_id '
            . 'WHERE i.source_key = :source_key'
        );
        $statement->execute(['source_key' => $sourceKey]);
        $actual = $statement->fetch() ?: [];
        foreach ($totals as $key => $expected) {
            if ((int) ($actual[$key] ?? -1) !== $expected) {
                throw new RuntimeException('Imported ' . $key . ' did not reconcile with the legacy export.');
            }
        }
        $passCheck = $this->database->prepare(
            'SELECT i.legacy_record_id FROM legacy_v1_imports AS i '
            . 'LEFT JOIN batch_passes AS p ON p.batch_id = i.batch_id '
            . 'WHERE i.source_key = :source_key GROUP BY i.batch_id '
            . 'HAVING COUNT(p.id) < 1 '
            . 'OR MIN(p.position) <> 1 OR MAX(p.position) <> COUNT(p.id) LIMIT 1'
        );
        $passCheck->execute(['source_key' => $sourceKey]);
        if ($passCheck->fetchColumn() !== false) {
            throw new RuntimeException('An imported batch has an invalid pass sequence.');
        }
    }

    /** @return list<string> */
    private function strains(mixed $raw, string $context): array
    {
        if (!is_array($raw)) {
            throw new RuntimeException($context . ' strains are not an array.');
        }
        $strains = [];
        $seen = [];
        foreach ($raw as $value) {
            $strain = trim($this->stringValue($value, $context . ' strain'));
            if ($strain === '' || mb_strlen($strain) > 120) {
                throw new RuntimeException($context . ' contains an invalid strain name.');
            }
            $key = mb_strtolower($strain);
            if (isset($seen[$key])) {
                throw new RuntimeException($context . ' contains the same strain more than once.');
            }
            $seen[$key] = true;
            $strains[] = $strain;
        }
        if ($strains === [] || count($strains) > 20) {
            throw new RuntimeException($context . ' must contain between 1 and 20 strains.');
        }
        return $strains;
    }

    /** @return list<array{position:int,micron:int,widthMm:float,lengthMm:float,layer:int}> */
    private function bags(mixed $raw, string $context): array
    {
        if (is_string($raw)) {
            try {
                $raw = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException($context . ' bag stack is invalid JSON.', previous: $error);
            }
        }
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw)) {
            throw new RuntimeException($context . ' bag stack is not an array.');
        }
        $bags = [];
        foreach (array_values($raw) as $position => $bag) {
            if (!is_array($bag)) {
                throw new RuntimeException($context . ' contains an invalid bag.');
            }
            $micron = $this->positiveInteger($bag['micron'] ?? null, 'bag micron', $position + 1);
            if ($micron > 500) {
                throw new RuntimeException($context . ' contains a bag above 500 microns.');
            }
            $size = trim($this->stringValue($bag['size'] ?? null, $context . ' bag size'));
            if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*[x×]\s*([0-9]+(?:\.[0-9]+)?)\s*(?:mm)?$/iuD', $size, $match) !== 1) {
                throw new RuntimeException($context . ' contains an unrecognized bag size.');
            }
            $width = (float) $match[1];
            $length = (float) $match[2];
            if ($width <= 0 || $width > 1000 || $length <= 0 || $length > 2000) {
                throw new RuntimeException($context . ' contains an unsupported millimetre bag size.');
            }
            $layer = $this->positiveInteger($bag['layer'] ?? ($position + 1), 'bag layer', $position + 1);
            $bags[] = [
                'position' => $position,
                'micron' => $micron,
                'widthMm' => $width,
                'lengthMm' => $length,
                'layer' => $layer,
            ];
        }
        return $bags;
    }

    /** @return list<array{upload:array<string,mixed>,decodedSha256:string}> */
    private function photos(mixed $raw, int $legacyId): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || count($raw) > 5) {
            throw new RuntimeException('Legacy record #' . $legacyId . ' has an invalid photograph count.');
        }
        $photos = [];
        foreach (array_values($raw) as $position => $dataUrl) {
            if (!is_string($dataUrl) || preg_match(
                '/\Adata:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+\/=\r\n]+)\z/iD',
                $dataUrl,
                $match,
            ) !== 1) {
                throw new RuntimeException('Legacy record #' . $legacyId . ' contains an invalid photograph data URL.');
            }
            $declaredMime = strtolower($match[1]);
            $bytes = base64_decode(str_replace(["\r", "\n"], '', $match[2]), true);
            if (!is_string($bytes) || $bytes === '') {
                throw new RuntimeException('Legacy record #' . $legacyId . ' contains invalid photograph data.');
            }
            $path = tempnam(sys_get_temp_dir(), 'rosin-v1-photo-');
            if (!is_string($path) || file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
                if (is_string($path)) {
                    @unlink($path);
                }
                throw new RuntimeException('A temporary imported photograph could not be created.');
            }
            chmod($path, 0600);
            $this->temporaryPhotoPaths[] = $path;
            unset($bytes);
            $extension = match ($declaredMime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            };
            $upload = $this->photoStorage->validateTrustedCliFile(
                $path,
                sprintf('legacy-batch-%d-photo-%d.%s', $legacyId, $position + 1, $extension),
            );
            if ($upload['mime_type'] !== $declaredMime) {
                throw new RuntimeException('Legacy record #' . $legacyId . ' photograph type does not match its data.');
            }
            $photos[] = ['upload' => $upload, 'decodedSha256' => (string) hash_file('sha256', $path)];
        }
        return $photos;
    }

    private function timestamp(mixed $raw, string $context): string
    {
        if (!is_string($raw) || preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $raw) !== 1) {
            throw new RuntimeException($context . ' has a timestamp without an explicit UTC offset.');
        }
        try {
            return (new DateTimeImmutable($raw))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable $error) {
            throw new RuntimeException($context . ' has an invalid timestamp.', previous: $error);
        }
    }

    private function finiteNumber(mixed $raw, string $label): float
    {
        if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric(trim($raw)))) {
            throw new RuntimeException($label . ' is not numeric.');
        }
        $value = (float) $raw;
        if (!is_finite($value)) {
            throw new RuntimeException($label . ' is not finite.');
        }
        return $value;
    }

    private function nullableFiniteNumber(mixed $raw, string $label): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return $this->finiteNumber($raw, $label);
    }

    private function positiveInteger(mixed $raw, string $label, int $record): int
    {
        $value = $this->finiteNumber($raw, 'Legacy ' . $label . ' for #' . $record);
        if ($value < 1 || floor($value) !== $value || $value > PHP_INT_MAX) {
            throw new RuntimeException('Legacy ' . $label . ' for #' . $record . ' is invalid.');
        }
        return (int) $value;
    }

    private function nullableNonNegativeInteger(mixed $raw, string $label, int $maximum): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = $this->finiteNumber($raw, $label);
        if ($value < 0 || floor($value) !== $value || $value > $maximum) {
            throw new RuntimeException($label . ' is invalid.');
        }
        return (int) $value;
    }

    private function stringValue(mixed $raw, string $label): string
    {
        if (!is_string($raw)) {
            throw new RuntimeException($label . ' is not text.');
        }
        return $raw;
    }

    private function assertSourceKey(string $sourceKey): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,96}$/D', $sourceKey) !== 1) {
            throw new RuntimeException('The source key must use 1–96 letters, numbers, dots, underscores, or hyphens.');
        }
    }

    private function removeTemporaryPhotos(): void
    {
        foreach ($this->temporaryPhotoPaths as $path) {
            if (str_starts_with(basename($path), 'rosin-v1-photo-') && is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
        $this->temporaryPhotoPaths = [];
    }
}
