<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use PDO;

final readonly class PhotoRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function nextPositionForBatch(int $batchId): int
    {
        $statement = $this->database->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM batch_photos WHERE batch_id = :batch_id'
        );
        $statement->execute(['batch_id' => $batchId]);
        return (int) $statement->fetchColumn();
    }

    /** @param array{storage_name: string, original_name: string, mime_type: string, byte_size: int} $photo */
    public function add(int $batchId, int $position, array $photo): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO batch_photos '
            . '(batch_id, position, storage_name, original_name, mime_type, byte_size, created_at) '
            . 'VALUES (:batch_id, :position, :storage_name, :original_name, :mime_type, :byte_size, :created_at)'
        );
        $statement->execute([
            'batch_id' => $batchId,
            'position' => $position,
            'storage_name' => $photo['storage_name'],
            'original_name' => $photo['original_name'],
            'mime_type' => $photo['mime_type'],
            'byte_size' => $photo['byte_size'],
            'created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
        return (int) $this->database->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $photoId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, batch_id, position, storage_name, original_name, mime_type, byte_size, created_at '
            . 'FROM batch_photos WHERE id = :id'
        );
        $statement->execute(['id' => $photoId]);
        $photo = $statement->fetch();
        return is_array($photo) ? $photo : null;
    }

    /** @return array<string, mixed>|null */
    public function remove(int $photoId): ?array
    {
        $photo = $this->find($photoId);
        if ($photo === null) {
            return null;
        }

        $statement = $this->database->prepare('DELETE FROM batch_photos WHERE id = :id');
        $statement->execute(['id' => $photoId]);
        return $photo;
    }
}
