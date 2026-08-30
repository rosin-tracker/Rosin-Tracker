<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use PDO;

final readonly class SettingsRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $statement = $this->database->prepare('SELECT value FROM settings WHERE key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $statement->execute([
            'key' => $key,
            'value' => $value,
            'updated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
    }
}
