<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use PDO;
use PDOException;
use RosinTracker\Domain\BatchData;
use RosinTracker\Domain\BatchTemplateData;
use RosinTracker\Domain\Json;
use RosinTracker\Domain\PassData;
use RosinTracker\Domain\ValidationException;

/** Settings-only snapshots. Updating a template never mutates an existing batch. */
final readonly class TemplateRepository
{
    public function __construct(private PDO $database)
    {
    }

    /** @return list<array{id:int,name:string,payload:array<string,mixed>,createdAt:string,updatedAt:string}> */
    public function all(): array
    {
        $rows = $this->database->query(
            'SELECT id, name, payload_json, created_at, updated_at '
            . 'FROM batch_templates ORDER BY name COLLATE NOCASE, id'
        )->fetchAll();

        return array_map($this->hydrate(...), array_values($rows));
    }

    /** @return list<array{id:int,name:string,payload:array<string,mixed>,formValues:array<string,mixed>,createdAt:string,updatedAt:string}> */
    public function allForForms(string $unitSystem): array
    {
        return array_map(static function (array $template) use ($unitSystem): array {
            $template['formValues'] = BatchTemplateData::fromPayload($template['payload'])
                ->toFormValues($unitSystem);
            return $template;
        }, $this->all());
    }

    /** @return array{id:int,name:string,payload:array<string,mixed>,createdAt:string,updatedAt:string}|null */
    public function find(int $id): ?array
    {
        $this->assertId($id);
        $statement = $this->database->prepare(
            'SELECT id, name, payload_json, created_at, updated_at FROM batch_templates WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function data(int $id): ?BatchTemplateData
    {
        $template = $this->find($id);
        return $template === null ? null : BatchTemplateData::fromPayload($template['payload']);
    }

    /** @return array<string, mixed>|null */
    public function formValues(int $id, string $unitSystem): ?array
    {
        return $this->data($id)?->toFormValues($unitSystem);
    }

    /** @return array{id:int,name:string,payload:array<string,mixed>,createdAt:string,updatedAt:string} */
    public function create(string $name, BatchTemplateData|array $template): array
    {
        $data = $this->templateData($template);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $statement = $this->database->prepare(
            'INSERT INTO batch_templates (name, payload_json, created_at, updated_at) '
            . 'VALUES (:name, :payload_json, :created_at, :updated_at)'
        );
        try {
            $statement->execute([
                'name' => $this->name($name),
                'payload_json' => Json::encode($data->toPayload()),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $error) {
            $this->throwFriendlyConflict($error);
            throw $error;
        }

        $created = $this->find((int) $this->database->lastInsertId());
        if ($created === null) {
            throw new PDOException('The template was inserted but could not be read back.');
        }
        return $created;
    }

    /** @return array{id:int,name:string,payload:array<string,mixed>,createdAt:string,updatedAt:string} */
    /** @param list<PassData> $passes */
    public function createFromBatch(string $name, BatchData $batch, array $passes = []): array
    {
        return $this->create($name, BatchTemplateData::fromBatch($batch, $passes));
    }

    /** @return array{id:int,name:string,payload:array<string,mixed>,createdAt:string,updatedAt:string}|null */
    public function update(int $id, string $name, BatchTemplateData|array $template): ?array
    {
        $this->assertId($id);
        if ($this->find($id) === null) {
            return null;
        }
        $data = $this->templateData($template);
        $statement = $this->database->prepare(
            'UPDATE batch_templates SET name = :name, payload_json = :payload_json, updated_at = :updated_at '
            . 'WHERE id = :id'
        );
        try {
            $statement->execute([
                'id' => $id,
                'name' => $this->name($name),
                'payload_json' => Json::encode($data->toPayload()),
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
        $statement = $this->database->prepare('DELETE FROM batch_templates WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->rowCount() > 0;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $payload = Json::decodeObject((string) $row['payload_json']);
        $payload = BatchTemplateData::fromPayload($payload)->toPayload();

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'payload' => $payload,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    private function templateData(BatchTemplateData|array $template): BatchTemplateData
    {
        return $template instanceof BatchTemplateData ? $template : BatchTemplateData::fromPayload($template);
    }

    private function name(string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name));
        if (!is_string($normalized) || mb_strlen($normalized) < 1 || mb_strlen($normalized) > 80) {
            throw new ValidationException(['name' => 'Use between 1 and 80 characters.']);
        }
        return $normalized;
    }

    private function assertId(int $id): void
    {
        if ($id < 1) {
            throw new ValidationException(['id' => 'The template ID is invalid.']);
        }
    }

    private function throwFriendlyConflict(PDOException $error): void
    {
        if ((string) $error->getCode() === '23000'
            || str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
            throw new ValidationException(['name' => 'A template with that name already exists.']);
        }
    }
}
