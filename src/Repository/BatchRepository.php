<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RosinTracker\Domain\BatchData;
use RosinTracker\Domain\PassData;
use RosinTracker\Domain\ValidationException;
use RuntimeException;
use Throwable;

/** Transactional storage for batches and their ordered strain, bag, and pass children. */
final readonly class BatchRepository
{
    private const SORTS = [
        'pressed_desc' => 'b.pressed_at DESC, b.id DESC',
        'pressed_asc' => 'b.pressed_at ASC, b.id ASC',
        'yield_desc' => '(b.yield_amount_g / b.start_amount_g) DESC, b.pressed_at DESC, b.id DESC',
        'yield_asc' => '(b.yield_amount_g / b.start_amount_g) ASC, b.pressed_at DESC, b.id DESC',
        'output_desc' => 'b.yield_amount_g DESC, b.pressed_at DESC, b.id DESC',
        'input_desc' => 'b.start_amount_g DESC, b.pressed_at DESC, b.id DESC',
        'material_asc' => 'b.start_material COLLATE NOCASE ASC, b.pressed_at DESC, b.id DESC',
        'strain_asc' => '(SELECT sort_strain.name FROM batch_strains AS sort_strain '
            . 'WHERE sort_strain.batch_id = b.id ORDER BY sort_strain.position, sort_strain.id LIMIT 1) '
            . 'COLLATE NOCASE ASC, b.pressed_at DESC, b.id DESC',
    ];

    public function __construct(private PDO $database)
    {
    }

    /** @return array<string, mixed> */
    public function create(BatchData $batch): array
    {
        if ($batch->numberOfPresses < 1 || $batch->numberOfPresses > PassRepository::MAX_PASSES) {
            throw new ValidationException([
                'number_of_presses' => 'A batch must contain between 1 and '
                    . PassRepository::MAX_PASSES . ' passes.',
            ]);
        }
        $id = $this->transactional(function () use ($batch): int {
            $this->assertSourceTemplateExists($batch);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $statement = $this->database->prepare(
                'INSERT INTO batches ('
                . 'pressed_at, start_material, start_amount_g, yield_amount_g, '
                . 'press_capacity_tons, humidity_percent, notes, source_template_id, created_at, updated_at'
                . ') VALUES ('
                . ':pressed_at, :start_material, :start_amount_g, :yield_amount_g, '
                . ':press_capacity_tons, :humidity_percent, :notes, :source_template_id, :created_at, :updated_at'
                . ')'
            );
            $statement->execute($this->batchLevelParameters($batch, $now) + ['created_at' => $now]);
            $id = (int) $this->database->lastInsertId();
            $this->insertChildren($id, $batch);
            $passes = new PassRepository($this->database);
            $firstPass = PassData::fromBatch($batch);
            // Compatibility for callers still submitting the former count:
            // materialize it as identical passes, just as migration 002 does.
            for ($position = 1; $position <= $batch->numberOfPresses; $position++) {
                $passes->create($id, $firstPass);
            }
            return $id;
        });

        $created = $this->find($id);
        if ($created === null) {
            throw new InvalidArgumentException('The batch was inserted but could not be read back.');
        }
        return $created;
    }

    /** @return array<string, mixed>|null */
    public function update(int $id, BatchData $batch): ?array
    {
        $this->assertId($id);
        $updated = $this->transactional(function () use ($id, $batch): bool {
            $exists = $this->database->prepare('SELECT 1 FROM batches WHERE id = :id');
            $exists->execute(['id' => $id]);
            if ($exists->fetchColumn() === false) {
                return false;
            }
            $this->assertSourceTemplateExists($batch);

            $statement = $this->database->prepare(
                'UPDATE batches SET '
                . 'pressed_at = :pressed_at, start_material = :start_material, '
                . 'start_amount_g = :start_amount_g, yield_amount_g = :yield_amount_g, '
                . 'press_capacity_tons = :press_capacity_tons, humidity_percent = :humidity_percent, '
                . 'notes = :notes, source_template_id = :source_template_id, updated_at = :updated_at '
                . 'WHERE id = :id'
            );
            $statement->execute($this->batchLevelParameters($batch, gmdate('Y-m-d\TH:i:s\Z')) + ['id' => $id]);

            $deleteStrains = $this->database->prepare('DELETE FROM batch_strains WHERE batch_id = :batch_id');
            $deleteStrains->execute(['batch_id' => $id]);
            $deleteBags = $this->database->prepare('DELETE FROM batch_bags WHERE batch_id = :batch_id');
            $deleteBags->execute(['batch_id' => $id]);
            $this->insertChildren($id, $batch);

            // Batch editing owns Pass 1's settings but must never collapse or
            // expand the independently managed pass list.
            $passes = new PassRepository($this->database);
            $existingPasses = $passes->listForBatch($id);
            if ($existingPasses === []) {
                $passes->create($id, PassData::fromBatch($batch));
            } else {
                $passes->update($id, (int) $existingPasses[0]['id'], PassData::fromBatch($batch));
            }
            return true;
        });

        return $updated ? $this->find($id) : null;
    }

    public function delete(int $id): bool
    {
        $this->assertId($id);
        $statement = $this->database->prepare('DELETE FROM batches WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $this->assertId($id);
        $statement = $this->database->prepare($this->baseSelect() . ' WHERE b.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateRows([$row])[0] ?? null;
    }

    public function data(int $id): ?BatchData
    {
        $record = $this->find($id);
        return $record === null ? null : $this->recordData($record);
    }

    /** @return array<string, mixed>|null */
    public function formValues(int $id, string $unitSystem, string $timezone = 'Europe/Copenhagen'): ?array
    {
        return $this->data($id)?->toFormValues($unitSystem, $timezone);
    }

    /**
     * Safely list/search batches. Unknown criteria are ignored; sort is an
     * allowlisted token and pagination is always bounded.
     *
     * @param array{
     *   query?:string,material?:string,pressedFrom?:string,pressedTo?:string,
     *   minYield?:int|float|string,maxYield?:int|float|string,
     *   sort?:string,limit?:int,offset?:int
     * } $criteria
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public function search(array $criteria = []): array
    {
        $limit = max(1, min((int) ($criteria['limit'] ?? 50), 100));
        $offset = max(0, min((int) ($criteria['offset'] ?? 0), 1000000));
        $sort = (string) ($criteria['sort'] ?? 'pressed_desc');
        if (!isset(self::SORTS[$sort])) {
            throw new ValidationException(['sort' => 'The selected sort order is invalid.']);
        }

        [$where, $parameters] = $this->filters($criteria);
        $count = $this->database->prepare('SELECT COUNT(*) FROM batches AS b' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();

        $statement = $this->database->prepare(
            $this->baseSelect() . $where . ' ORDER BY ' . self::SORTS[$sort] . ' LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $this->hydrateRows(array_values($statement->fetchAll())),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** @return array<string, mixed> */
    private function batchLevelParameters(BatchData $batch, string $updatedAt): array
    {
        return [
            'pressed_at' => $batch->pressedAt,
            'start_material' => $batch->startMaterial,
            'start_amount_g' => $batch->startAmountG,
            'yield_amount_g' => $batch->yieldAmountG,
            'press_capacity_tons' => $batch->pressCapacityTons,
            'humidity_percent' => $batch->humidityPercent,
            'notes' => $batch->notes,
            'source_template_id' => $batch->sourceTemplateId,
            'updated_at' => $updatedAt,
        ];
    }

    private function insertChildren(int $batchId, BatchData $batch): void
    {
        $strainStatement = $this->database->prepare(
            'INSERT INTO batch_strains (batch_id, position, name, amount_g) '
            . 'VALUES (:batch_id, :position, :name, :amount_g)'
        );
        foreach ($batch->strains as $position => $strain) {
            $strainStatement->execute([
                'batch_id' => $batchId,
                'position' => $position,
                'name' => $strain,
                'amount_g' => $batch->strainAmountsG[$position] ?? null,
            ]);
        }

        $bagStatement = $this->database->prepare(
            'INSERT INTO batch_bags (batch_id, position, brand, micron, width_mm, length_mm, layer) '
            . 'VALUES (:batch_id, :position, :brand, :micron, :width_mm, :length_mm, :layer)'
        );
        foreach ($batch->bags as $position => $bag) {
            $bagStatement->execute([
                'batch_id' => $batchId,
                'position' => $position,
                'brand' => $bag['brand'] ?? null,
                'micron' => $bag['micron'],
                'width_mm' => $bag['widthMm'],
                'length_mm' => $bag['lengthMm'],
                'layer' => $bag['layer'],
            ]);
        }
    }

    private function baseSelect(): string
    {
        return 'SELECT b.*, t.name AS source_template_name, '
            . '((b.yield_amount_g / b.start_amount_g) * 100.0) AS yield_percentage '
            . 'FROM batches AS b LEFT JOIN batch_templates AS t ON t.id = b.source_template_id';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $records = [];
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $ids[] = $id;
            $records[$id] = $this->hydrateBatch($row);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $strains = $this->database->prepare(
            "SELECT batch_id, name, amount_g FROM batch_strains WHERE batch_id IN ({$placeholders}) "
            . 'ORDER BY batch_id, position, id'
        );
        $strains->execute($ids);
        foreach ($strains->fetchAll() as $row) {
            $records[(int) $row['batch_id']]['strains'][] = (string) $row['name'];
            $records[(int) $row['batch_id']]['strainAmountsG'][] = $row['amount_g'] === null
                ? null
                : (float) $row['amount_g'];
        }

        $bags = $this->database->prepare(
            "SELECT id, batch_id, position, brand, micron, width_mm, length_mm, layer FROM batch_bags "
            . "WHERE batch_id IN ({$placeholders}) ORDER BY batch_id, position, id"
        );
        $bags->execute($ids);
        foreach ($bags->fetchAll() as $row) {
            $records[(int) $row['batch_id']]['bags'][] = [
                'id' => (int) $row['id'],
                'position' => (int) $row['position'],
                'brand' => $row['brand'] === null ? null : (string) $row['brand'],
                'micron' => (int) $row['micron'],
                'widthMm' => (float) $row['width_mm'],
                'lengthMm' => (float) $row['length_mm'],
                'layer' => (int) $row['layer'],
            ];
        }

        $passes = $this->database->prepare(
            "SELECT id, batch_id, position, temperature_c, pressure_bar, preheat_seconds, "
            . "press_duration_seconds, created_at, updated_at FROM batch_passes "
            . "WHERE batch_id IN ({$placeholders}) ORDER BY batch_id, position, id"
        );
        $passes->execute($ids);
        foreach ($passes->fetchAll() as $row) {
            $records[(int) $row['batch_id']]['passes'][] = [
                'id' => (int) $row['id'],
                'batchId' => (int) $row['batch_id'],
                'position' => (int) $row['position'],
                'temperatureC' => (float) $row['temperature_c'],
                'pressureBar' => $row['pressure_bar'] === null ? null : (float) $row['pressure_bar'],
                'preheatSeconds' => $row['preheat_seconds'] === null ? null : (int) $row['preheat_seconds'],
                'pressDurationSeconds' => $row['press_duration_seconds'] === null
                    ? null
                    : (int) $row['press_duration_seconds'],
                'createdAt' => (string) $row['created_at'],
                'updatedAt' => (string) $row['updated_at'],
            ];
        }
        foreach ($records as &$record) {
            if ($record['passes'] === []) {
                throw new RuntimeException('Every batch must contain a first pass.');
            }
            $firstPass = $record['passes'][0];
            $record['temperatureC'] = $firstPass['temperatureC'];
            $record['pressureBar'] = $firstPass['pressureBar'];
            $record['preheatSeconds'] = $firstPass['preheatSeconds'];
            $record['pressDurationSeconds'] = $firstPass['pressDurationSeconds'];
            $record['numberOfPresses'] = count($record['passes']);
        }
        unset($record);

        $photos = $this->database->prepare(
            "SELECT id, batch_id, position, storage_name, original_name, mime_type, byte_size, created_at "
            . "FROM batch_photos WHERE batch_id IN ({$placeholders}) ORDER BY batch_id, position, id"
        );
        $photos->execute($ids);
        foreach ($photos->fetchAll() as $row) {
            $records[(int) $row['batch_id']]['photos'][] = [
                'id' => (int) $row['id'],
                'position' => (int) $row['position'],
                'storageName' => (string) $row['storage_name'],
                'originalName' => (string) $row['original_name'],
                'mimeType' => (string) $row['mime_type'],
                'byteSize' => (int) $row['byte_size'],
                'createdAt' => (string) $row['created_at'],
            ];
        }

        return array_values(array_map(static fn (int $id): array => $records[$id], $ids));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrateBatch(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'pressedAt' => (string) $row['pressed_at'],
            'startMaterial' => (string) $row['start_material'],
            'startAmountG' => (float) $row['start_amount_g'],
            'yieldAmountG' => (float) $row['yield_amount_g'],
            'yieldPercentage' => round((float) $row['yield_percentage'], 2),
            'pressCapacityTons' => $row['press_capacity_tons'] === null ? null : (float) $row['press_capacity_tons'],
            'humidityPercent' => $row['humidity_percent'] === null ? null : (float) $row['humidity_percent'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'sourceTemplateId' => $row['source_template_id'] === null ? null : (int) $row['source_template_id'],
            'sourceTemplateName' => $row['source_template_name'] === null ? null : (string) $row['source_template_name'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
            'strains' => [],
            'strainAmountsG' => [],
            'bags' => [],
            'passes' => [],
            'photos' => [],
        ];
    }

    /** @param array<string, mixed> $record */
    private function recordData(array $record): BatchData
    {
        return new BatchData(
            pressedAt: (string) $record['pressedAt'],
            startMaterial: (string) $record['startMaterial'],
            startAmountG: (float) $record['startAmountG'],
            yieldAmountG: (float) $record['yieldAmountG'],
            temperatureC: (float) $record['temperatureC'],
            pressureBar: $record['pressureBar'] === null ? null : (float) $record['pressureBar'],
            pressCapacityTons: $record['pressCapacityTons'] === null ? null : (float) $record['pressCapacityTons'],
            humidityPercent: $record['humidityPercent'] === null ? null : (float) $record['humidityPercent'],
            pressDurationSeconds: $record['pressDurationSeconds'] === null ? null : (int) $record['pressDurationSeconds'],
            preheatSeconds: $record['preheatSeconds'] === null ? null : (int) $record['preheatSeconds'],
            numberOfPresses: (int) $record['numberOfPresses'],
            notes: $record['notes'] === null ? null : (string) $record['notes'],
            sourceTemplateId: $record['sourceTemplateId'] === null ? null : (int) $record['sourceTemplateId'],
            strains: array_values($record['strains']),
            bags: array_map(static fn (array $bag): array => [
                'position' => (int) $bag['position'],
                'brand' => ($bag['brand'] ?? null) === null ? null : (string) $bag['brand'],
                'micron' => (int) $bag['micron'],
                'widthMm' => (float) $bag['widthMm'],
                'lengthMm' => (float) $bag['lengthMm'],
                'layer' => (int) $bag['layer'],
            ], array_values($record['bags'])),
            strainAmountsG: array_values($record['strainAmountsG']),
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{string, array<string, mixed>}
     */
    private function filters(array $criteria): array
    {
        $conditions = [];
        $parameters = [];
        $query = trim((string) ($criteria['query'] ?? ''));
        if ($query !== '') {
            if (mb_strlen($query) > 120) {
                throw new ValidationException(['query' => 'Search terms must be no longer than 120 characters.']);
            }
            $parameters['search_material'] = $query;
            $parameters['search_notes'] = $query;
            $parameters['search_strain'] = $query;
            $conditions[] = '(instr(lower(b.start_material), lower(:search_material)) > 0 '
                . 'OR instr(lower(COALESCE(b.notes, \'\')), lower(:search_notes)) > 0 '
                . 'OR EXISTS (SELECT 1 FROM batch_strains AS search_strain '
                . 'WHERE search_strain.batch_id = b.id '
                . 'AND instr(lower(search_strain.name), lower(:search_strain)) > 0))';
        }

        $material = trim((string) ($criteria['material'] ?? ''));
        if ($material !== '' && strtolower($material) !== 'all') {
            if (mb_strlen($material) > 120) {
                throw new ValidationException(['material' => 'The material filter is too long.']);
            }
            $conditions[] = 'b.start_material = :material COLLATE NOCASE';
            $parameters['material'] = $material;
        }
        if (isset($criteria['pressedFrom']) && trim((string) $criteria['pressedFrom']) !== '') {
            $conditions[] = 'b.pressed_at >= :pressed_from';
            $parameters['pressed_from'] = $this->timestamp((string) $criteria['pressedFrom'], false);
        }
        if (isset($criteria['pressedTo']) && trim((string) $criteria['pressedTo']) !== '') {
            $conditions[] = 'b.pressed_at <= :pressed_to';
            $parameters['pressed_to'] = $this->timestamp((string) $criteria['pressedTo'], true);
        }
        foreach (['minYield' => '>=', 'maxYield' => '<='] as $key => $operator) {
            if (!isset($criteria[$key]) || trim((string) $criteria[$key]) === '') {
                continue;
            }
            if (!is_numeric($criteria[$key]) || !is_finite((float) $criteria[$key])) {
                throw new ValidationException([$key => 'Enter a valid yield percentage.']);
            }
            $value = (float) $criteria[$key];
            if ($value < 0 || $value > 100) {
                throw new ValidationException([$key => 'Yield filters must be between 0 and 100 percent.']);
            }
            $parameter = $key === 'minYield' ? 'min_yield' : 'max_yield';
            $conditions[] = "((b.yield_amount_g / b.start_amount_g) * 100.0) {$operator} :{$parameter}";
            $parameters[$parameter] = $value;
        }
        if (isset($parameters['min_yield'], $parameters['max_yield'])
            && $parameters['min_yield'] > $parameters['max_yield']) {
            throw new ValidationException(['maxYield' => 'Maximum yield must not be below minimum yield.']);
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $parameters];
    }

    private function timestamp(string $value, bool $endOfDay): string
    {
        try {
            $normalized = trim($value);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $normalized) === 1) {
                $normalized .= $endOfDay ? 'T23:59:59' : 'T00:00:00';
            } elseif (preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D',
                $normalized,
            ) !== 1) {
                throw new InvalidArgumentException('Invalid timestamp.');
            }
            $date = new DateTimeImmutable($normalized);
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable $error) {
            throw new ValidationException(['date' => 'Enter a valid date and time.']);
        }
    }

    private function assertId(int $id): void
    {
        if ($id < 1) {
            throw new ValidationException(['id' => 'The batch ID is invalid.']);
        }
    }

    private function assertSourceTemplateExists(BatchData $batch): void
    {
        if ($batch->sourceTemplateId === null) {
            return;
        }
        $statement = $this->database->prepare('SELECT 1 FROM batch_templates WHERE id = :id');
        $statement->execute(['id' => $batch->sourceTemplateId]);
        if ($statement->fetchColumn() === false) {
            throw new ValidationException([
                'source_template_id' => 'The selected template is no longer available.',
            ]);
        }
    }

    /** @template T @param callable():T $callback @return T */
    private function transactional(callable $callback): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->database->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }
}
