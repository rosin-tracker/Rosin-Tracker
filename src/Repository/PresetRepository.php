<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use PDO;
use PDOException;
use RosinTracker\Domain\Json;
use RosinTracker\Domain\ValidationException;

/** Generic, editable suggestions keyed by the form field that consumes them. */
final readonly class PresetRepository
{
    public function __construct(private PDO $database)
    {
    }

    /** @return list<array{id:int,fieldKey:string,label:string,value:mixed,sortOrder:int,createdAt:string,updatedAt:string}> */
    public function all(): array
    {
        $rows = $this->database->query(
            'SELECT id, field_key, label, value_json, sort_order, created_at, updated_at '
            . 'FROM preset_options ORDER BY field_key, sort_order, label COLLATE NOCASE, id'
        )->fetchAll();

        return array_map($this->hydrate(...), array_values($rows));
    }

    /** @return array<string, list<array{id:int,fieldKey:string,label:string,value:mixed,sortOrder:int,createdAt:string,updatedAt:string}>> */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->all() as $option) {
            $groups[$option['fieldKey']][] = $option;
        }

        return $groups;
    }

    /** @return list<array{id:int,fieldKey:string,label:string,value:mixed,sortOrder:int,createdAt:string,updatedAt:string}> */
    public function forField(string $fieldKey): array
    {
        $field = $this->fieldKey($fieldKey);
        $statement = $this->database->prepare(
            'SELECT id, field_key, label, value_json, sort_order, created_at, updated_at '
            . 'FROM preset_options WHERE field_key = :field_key '
            . 'ORDER BY sort_order, label COLLATE NOCASE, id'
        );
        $statement->execute(['field_key' => $field]);

        return array_map($this->hydrate(...), array_values($statement->fetchAll()));
    }

    /** @return array{id:int,fieldKey:string,label:string,value:mixed,sortOrder:int,createdAt:string,updatedAt:string}|null */
    public function find(int $id): ?array
    {
        $this->assertId($id);
        $statement = $this->database->prepare(
            'SELECT id, field_key, label, value_json, sort_order, created_at, updated_at '
            . 'FROM preset_options WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array{id:int,fieldKey:string,label:string,value:mixed,sortOrder:int,createdAt:string,updatedAt:string} */
    public function create(string $fieldKey, string $label, mixed $value, int $sortOrder = 0): array
    {
        $field = $this->fieldKey($fieldKey);
        $normalizedLabel = $this->label($label);
        $order = $this->sortOrder($sortOrder);
        $json = Json::encode($value, 65536);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $statement = $this->database->prepare(
            'INSERT INTO preset_options '
            . '(field_key, label, value_json, sort_order, created_at, updated_at) '
            . 'VALUES (:field_key, :label, :value_json, :sort_order, :created_at, :updated_at)'
        );

        try {
            $statement->execute([
                'field_key' => $field,
                'label' => $normalizedLabel,
                'value_json' => $json,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $error) {
            $this->throwFriendlyConflict($error);
            throw $error;
        }

        $created = $this->find((int) $this->database->lastInsertId());
        if ($created === null) {
            throw new PDOException('The preset was inserted but could not be read back.');
        }

        return $created;
    }

    /** @return array{id:int,fieldKey:string,label:string,value:mixed,sortOrder:int,createdAt:string,updatedAt:string}|null */
    public function update(int $id, string $fieldKey, string $label, mixed $value, int $sortOrder = 0): ?array
    {
        $this->assertId($id);
        if ($this->find($id) === null) {
            return null;
        }
        $statement = $this->database->prepare(
            'UPDATE preset_options SET '
            . 'field_key = :field_key, label = :label, value_json = :value_json, '
            . 'sort_order = :sort_order, updated_at = :updated_at WHERE id = :id'
        );

        try {
            $statement->execute([
                'id' => $id,
                'field_key' => $this->fieldKey($fieldKey),
                'label' => $this->label($label),
                'value_json' => Json::encode($value, 65536),
                'sort_order' => $this->sortOrder($sortOrder),
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        } catch (PDOException $error) {
            $this->throwFriendlyConflict($error);
            throw $error;
        }

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $this->assertId($id);
        $statement = $this->database->prepare('DELETE FROM preset_options WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->rowCount() > 0;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fieldKey' => (string) $row['field_key'],
            'label' => (string) $row['label'],
            'value' => Json::decode((string) $row['value_json']),
            'sortOrder' => (int) $row['sort_order'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    private function fieldKey(string $fieldKey): string
    {
        $field = strtolower(trim($fieldKey));
        if (mb_strlen($field) < 1 || mb_strlen($field) > 64
            || preg_match('/^[a-z][a-z0-9_.-]*$/D', $field) !== 1) {
            throw new ValidationException([
                'field_key' => 'Use a lowercase field key containing letters, numbers, dots, hyphens, or underscores.',
            ]);
        }

        return $field;
    }

    private function label(string $label): string
    {
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $label);
        $normalized = is_string($trimmed) ? preg_replace('/\s+/u', ' ', $trimmed) : null;
        if (!is_string($normalized) || mb_strlen($normalized) < 1 || mb_strlen($normalized) > 120) {
            throw new ValidationException(['label' => 'Use between 1 and 120 characters.']);
        }

        return $normalized;
    }

    private function sortOrder(int $sortOrder): int
    {
        if ($sortOrder < -100000 || $sortOrder > 100000) {
            throw new ValidationException(['sort_order' => 'The sort order is out of range.']);
        }
        return $sortOrder;
    }

    private function assertId(int $id): void
    {
        if ($id < 1) {
            throw new ValidationException(['id' => 'The preset ID is invalid.']);
        }
    }

    private function throwFriendlyConflict(PDOException $error): void
    {
        if ((string) $error->getCode() === '23000'
            || str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
            throw new ValidationException(['name' => 'That saved option already exists.']);
        }
    }
}
