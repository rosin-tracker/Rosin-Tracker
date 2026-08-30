<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use InvalidArgumentException;
use PDO;
use RosinTracker\Domain\PassData;
use RosinTracker\Domain\ValidationException;
use RuntimeException;
use Throwable;

/** Transactional storage for the ordered passes belonging to one batch. */
final readonly class PassRepository
{
    public const MAX_PASSES = 20;

    public function __construct(private PDO $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForBatch(int $batchId): array
    {
        $this->assertId($batchId, 'batch_id');
        $statement = $this->database->prepare(
            'SELECT id, batch_id, position, temperature_c, pressure_bar, preheat_seconds, '
            . 'press_duration_seconds, created_at, updated_at '
            . 'FROM batch_passes WHERE batch_id = :batch_id ORDER BY position, id'
        );
        $statement->execute(['batch_id' => $batchId]);

        return array_map($this->hydrate(...), array_values($statement->fetchAll()));
    }

    /** @return array<string, mixed>|null */
    public function find(int $batchId, int $passId): ?array
    {
        $this->assertId($batchId, 'batch_id');
        $this->assertId($passId, 'pass_id');
        $statement = $this->database->prepare(
            'SELECT id, batch_id, position, temperature_c, pressure_bar, preheat_seconds, '
            . 'press_duration_seconds, created_at, updated_at '
            . 'FROM batch_passes WHERE id = :id AND batch_id = :batch_id'
        );
        $statement->execute(['id' => $passId, 'batch_id' => $batchId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function data(int $batchId, int $passId): ?PassData
    {
        $record = $this->find($batchId, $passId);
        return $record === null ? null : $this->recordData($record);
    }

    /** @return array<string, mixed> */
    public function create(int $batchId, PassData $pass): array
    {
        $this->assertId($batchId, 'batch_id');
        $passId = $this->transactional(function () use ($batchId, $pass): int {
            $this->assertBatchExists($batchId);
            $positionStatement = $this->database->prepare(
                'SELECT COUNT(*) AS pass_count, COALESCE(MAX(position), 0) AS final_position '
                . 'FROM batch_passes WHERE batch_id = :batch_id'
            );
            $positionStatement->execute(['batch_id' => $batchId]);
            $positions = $positionStatement->fetch() ?: [];
            $count = (int) ($positions['pass_count'] ?? 0);
            if ($count >= self::MAX_PASSES) {
                throw new ValidationException([
                    'pass' => 'A batch can contain no more than ' . self::MAX_PASSES . ' passes.',
                ]);
            }
            $position = (int) ($positions['final_position'] ?? 0) + 1;
            if ($position > self::MAX_PASSES) {
                throw new RuntimeException('The pass order is invalid and could not be extended.');
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $statement = $this->database->prepare(
                'INSERT INTO batch_passes ('
                . 'batch_id, position, temperature_c, pressure_bar, preheat_seconds, '
                . 'press_duration_seconds, created_at, updated_at'
                . ') VALUES ('
                . ':batch_id, :position, :temperature_c, :pressure_bar, :preheat_seconds, '
                . ':press_duration_seconds, :created_at, :updated_at'
                . ')'
            );
            $statement->execute($this->parameters($pass) + [
                'batch_id' => $batchId,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $passId = (int) $this->database->lastInsertId();
            $this->touchBatch($batchId, $now);
            return $passId;
        });

        $created = $this->find($batchId, $passId);
        if ($created === null) {
            throw new RuntimeException('The pass was inserted but could not be read back.');
        }
        return $created;
    }

    /** @return array<string, mixed>|null */
    public function update(int $batchId, int $passId, PassData $pass): ?array
    {
        $this->assertId($batchId, 'batch_id');
        $this->assertId($passId, 'pass_id');
        $updated = $this->transactional(function () use ($batchId, $passId, $pass): bool {
            $existing = $this->find($batchId, $passId);
            if ($existing === null) {
                return false;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $statement = $this->database->prepare(
                'UPDATE batch_passes SET temperature_c = :temperature_c, pressure_bar = :pressure_bar, '
                . 'preheat_seconds = :preheat_seconds, press_duration_seconds = :press_duration_seconds, '
                . 'updated_at = :updated_at WHERE id = :id AND batch_id = :batch_id'
            );
            $statement->execute($this->parameters($pass) + [
                'updated_at' => $now,
                'id' => $passId,
                'batch_id' => $batchId,
            ]);
            $this->touchBatch($batchId, $now);
            return true;
        });

        return $updated ? $this->find($batchId, $passId) : null;
    }

    /**
     * Delete one pass and close the position gap. The final pass is protected
     * because a batch without a first pass has no usable press settings.
     */
    public function delete(int $batchId, int $passId): bool
    {
        $this->assertId($batchId, 'batch_id');
        $this->assertId($passId, 'pass_id');

        return $this->transactional(function () use ($batchId, $passId): bool {
            $existing = $this->find($batchId, $passId);
            if ($existing === null) {
                return false;
            }
            $countStatement = $this->database->prepare(
                'SELECT COUNT(*) FROM batch_passes WHERE batch_id = :batch_id'
            );
            $countStatement->execute(['batch_id' => $batchId]);
            if ((int) $countStatement->fetchColumn() <= 1) {
                throw new ValidationException(['pass' => 'Every batch must keep at least one pass.']);
            }

            $delete = $this->database->prepare(
                'DELETE FROM batch_passes WHERE id = :id AND batch_id = :batch_id'
            );
            $delete->execute(['id' => $passId, 'batch_id' => $batchId]);

            // Move rows one at a time from low to high so the UNIQUE(batch_id,
            // position) constraint is never transiently violated.
            $following = $this->database->prepare(
                'SELECT id, position FROM batch_passes '
                . 'WHERE batch_id = :batch_id AND position > :position ORDER BY position, id'
            );
            $following->execute([
                'batch_id' => $batchId,
                'position' => (int) $existing['position'],
            ]);
            $move = $this->database->prepare(
                'UPDATE batch_passes SET position = :position WHERE id = :id AND batch_id = :batch_id'
            );
            foreach ($following->fetchAll() as $row) {
                $move->execute([
                    'position' => (int) $row['position'] - 1,
                    'id' => (int) $row['id'],
                    'batch_id' => $batchId,
                ]);
            }

            $this->touchBatch($batchId, gmdate('Y-m-d\TH:i:s\Z'));
            return true;
        });
    }

    /** Return an unsaved copy of the final pass for the Add Pass form. */
    public function duplicateLastDefaults(int $batchId): ?PassData
    {
        $this->assertId($batchId, 'batch_id');
        $statement = $this->database->prepare(
            'SELECT temperature_c, pressure_bar, preheat_seconds, press_duration_seconds '
            . 'FROM batch_passes WHERE batch_id = :batch_id ORDER BY position DESC, id DESC LIMIT 1'
        );
        $statement->execute(['batch_id' => $batchId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new PassData(
            temperatureC: (float) $row['temperature_c'],
            pressureBar: $row['pressure_bar'] === null ? null : (float) $row['pressure_bar'],
            preheatSeconds: $row['preheat_seconds'] === null ? null : (int) $row['preheat_seconds'],
            pressDurationSeconds: $row['press_duration_seconds'] === null
                ? null
                : (int) $row['press_duration_seconds'],
        );
    }

    /** @return array<string, int|float|string>|null */
    public function duplicateLastFormValues(int $batchId, string $unitSystem): ?array
    {
        return $this->duplicateLastDefaults($batchId)?->toFormValues($unitSystem);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        return [
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

    /** @param array<string, mixed> $record */
    private function recordData(array $record): PassData
    {
        return new PassData(
            temperatureC: (float) $record['temperatureC'],
            pressureBar: $record['pressureBar'] === null ? null : (float) $record['pressureBar'],
            preheatSeconds: $record['preheatSeconds'] === null ? null : (int) $record['preheatSeconds'],
            pressDurationSeconds: $record['pressDurationSeconds'] === null
                ? null
                : (int) $record['pressDurationSeconds'],
        );
    }

    /** @return array<string, int|float|null> */
    private function parameters(PassData $pass): array
    {
        return [
            'temperature_c' => $pass->temperatureC,
            'pressure_bar' => $pass->pressureBar,
            'preheat_seconds' => $pass->preheatSeconds,
            'press_duration_seconds' => $pass->pressDurationSeconds,
        ];
    }

    private function touchBatch(int $batchId, string $updatedAt): void
    {
        $statement = $this->database->prepare(
            'UPDATE batches SET updated_at = :updated_at WHERE id = :batch_id'
        );
        $statement->execute(['updated_at' => $updatedAt, 'batch_id' => $batchId]);
    }

    private function assertBatchExists(int $batchId): void
    {
        $statement = $this->database->prepare('SELECT 1 FROM batches WHERE id = :id');
        $statement->execute(['id' => $batchId]);
        if ($statement->fetchColumn() === false) {
            throw new InvalidArgumentException('The batch does not exist.');
        }
    }

    private function assertId(int $id, string $field): void
    {
        if ($id < 1) {
            throw new ValidationException([$field => 'This ID is invalid.']);
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
