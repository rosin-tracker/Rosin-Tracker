<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use PDO;

final readonly class DashboardRepository
{
    public function __construct(private PDO $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function recentBatches(int $limit = 2, ?string $material = null): array
    {
        $where = '';
        $parameters = [];
        if ($material !== null && trim($material) !== '') {
            $where = ' WHERE TRIM(b.start_material) = TRIM(:material) COLLATE NOCASE';
            $parameters['material'] = trim($material);
        }
        $statement = $this->database->prepare(
            'SELECT b.*, '
            . '((b.yield_amount_g / b.start_amount_g) * 100) AS yield_percentage, '
            . '(SELECT group_concat(ordered.name, \' • \') FROM ('
            . 'SELECT name FROM batch_strains WHERE batch_id = b.id ORDER BY position'
            . ') AS ordered) AS strain_names '
            . 'FROM batches AS b' . $where . ' ORDER BY b.pressed_at DESC, b.id DESC LIMIT :limit'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue('limit', max(1, min($limit, 10)), PDO::PARAM_INT);
        $statement->execute();

        return array_values($statement->fetchAll());
    }
}
