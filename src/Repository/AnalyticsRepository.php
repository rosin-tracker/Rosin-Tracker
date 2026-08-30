<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use PDO;

final readonly class AnalyticsRepository
{
    private const STRAIN_SEPARATOR = "\x1F";

    public function __construct(private PDO $database)
    {
    }

    /** @return list<string> */
    public function materialOptions(): array
    {
        $statement = $this->database->query(
            'SELECT b.start_material FROM batches AS b '
            . 'INNER JOIN ('
            . 'SELECT LOWER(TRIM(start_material)) AS material_key, MIN(id) AS first_id '
            . 'FROM batches GROUP BY LOWER(TRIM(start_material))'
            . ') AS first_material ON first_material.first_id = b.id '
            . 'ORDER BY b.start_material COLLATE NOCASE, b.id'
        );

        return array_values(array_map(
            static fn (mixed $material): string => trim((string) $material),
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ));
    }

    /** @return list<string> */
    public function strainOptions(?string $material = null): array
    {
        [$materialSql, $parameters] = $this->optionalMaterialAnd($material, 'first_batch');
        $statement = $this->database->prepare(
            'SELECT s.name FROM batch_strains AS s '
            . 'INNER JOIN ('
            . 'SELECT LOWER(TRIM(first_strain.name)) AS strain_key, MIN(first_strain.id) AS first_id '
            . 'FROM batch_strains AS first_strain '
            . 'INNER JOIN batches AS first_batch ON first_batch.id = first_strain.batch_id '
            . 'WHERE 1 = 1' . $materialSql . ' '
            . 'GROUP BY LOWER(TRIM(first_strain.name))'
            . ') AS first_match ON first_match.first_id = s.id '
            . 'ORDER BY s.name COLLATE NOCASE, s.id'
        );
        $statement->execute($parameters);

        return array_values(array_map(
            static fn (mixed $strain): string => trim((string) $strain),
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ));
    }

    /**
     * @return array{
     *   totalBatches:int,
     *   averageYield:float,
     *   medianYield:float,
     *   yieldConsistency:float,
     *   overallYield:float,
     *   bestYield:float,
     *   totalInputG:float,
     *   totalYieldG:float
     * }
     */
    public function summary(?string $material = null, ?string $strain = null): array
    {
        return $this->metrics($this->observations($material, $strain));
    }

    /**
     * Return the highest-yield matching batch. Equal yields prefer the newer
     * batch number so the result is deterministic.
     *
     * @return null|array{
     *   id:int,pressedAt:string,material:string,startAmountG:float,yieldAmountG:float,
     *   yieldPercentage:float,temperatureC:float,passCount:int,
     *   strainList:list<string>,strains:string
     * }
     */
    public function highestYieldBatch(?string $material = null, ?string $strain = null): ?array
    {
        $highest = null;
        foreach ($this->observations($material, $strain) as $batch) {
            if (
                $highest === null
                || $batch['yieldPercentage'] > $highest['yieldPercentage']
                || (
                    $batch['yieldPercentage'] === $highest['yieldPercentage']
                    && $batch['id'] > $highest['id']
                )
            ) {
                $highest = $batch;
            }
        }

        return $highest;
    }

    /**
     * @return list<array{
     *   material:string,batchCount:int,averageYield:float,medianYield:float,
     *   overallYield:float,bestYield:float
     * }>
     */
    public function materialPerformance(?string $strain = null): array
    {
        return $this->groupPerformance(
            $this->observations(null, $strain),
            static fn (array $batch): ?array => [
                mb_strtolower(trim($batch['material'])),
                trim($batch['material']),
            ],
            'material',
        );
    }

    /**
     * Only batches containing exactly one strain are credited to a strain.
     *
     * @return list<array{
     *   strain:string,batchCount:int,averageYield:float,medianYield:float,
     *   overallYield:float,bestYield:float
     * }>
     */
    public function strainPerformance(?string $material = null): array
    {
        return $this->groupPerformance(
            $this->observations($material),
            static function (array $batch): ?array {
                if (count($batch['strainList']) !== 1) {
                    return null;
                }
                $strain = trim($batch['strainList'][0]);
                return [mb_strtolower($strain), $strain];
            },
            'strain',
        );
    }

    /**
     * Multi-strain batches are grouped as exact combinations. Strain position
     * is form-entry order, not a physical blend property, so order is ignored.
     *
     * @return list<array{
     *   blend:string,batchCount:int,averageYield:float,medianYield:float,
     *   overallYield:float,bestYield:float
     * }>
     */
    public function blendPerformance(?string $material = null): array
    {
        return $this->groupPerformance(
            $this->observations($material),
            static function (array $batch): ?array {
                if (count($batch['strainList']) < 2) {
                    return null;
                }
                $strains = array_map('trim', $batch['strainList']);
                usort($strains, 'strcasecmp');
                $key = implode(self::STRAIN_SEPARATOR, array_map('mb_strtolower', $strains));
                return [$key, implode(' + ', $strains)];
            },
            'blend',
        );
    }

    /**
     * @return array{
     *   points:list<array{
     *     id:int,material:string,strains:string,temperatureC:float,
     *     yieldPercentage:float,passCount:int
     *   }>,
     *   bands:list<array{
     *     temperatureC:float,batchCount:int,averageYield:float,medianYield:float
     *   }>
     * }
     */
    public function temperatureYield(?string $material = null, ?string $strain = null): array
    {
        $observations = $this->observations($material, $strain);
        $points = [];
        /** @var array<string, array{temperatureC:float,yields:list<float>}> $grouped */
        $grouped = [];

        foreach ($observations as $batch) {
            $points[] = [
                'id' => $batch['id'],
                'material' => $batch['material'],
                'strains' => $batch['strains'],
                'temperatureC' => $batch['temperatureC'],
                'yieldPercentage' => $batch['yieldPercentage'],
                'passCount' => $batch['passCount'],
            ];
            $band = round($batch['temperatureC'] / 5.0) * 5.0;
            $key = number_format($band, 1, '.', '');
            $grouped[$key] ??= ['temperatureC' => $band, 'yields' => []];
            $grouped[$key]['yields'][] = $batch['yieldPercentage'];
        }

        usort($points, static fn (array $left, array $right): int =>
            ($left['temperatureC'] <=> $right['temperatureC']) ?: ($left['id'] <=> $right['id']));
        uasort($grouped, static fn (array $left, array $right): int =>
            $left['temperatureC'] <=> $right['temperatureC']);

        $bands = [];
        foreach ($grouped as $group) {
            $bands[] = [
                'temperatureC' => round($group['temperatureC'], 2),
                'batchCount' => count($group['yields']),
                'averageYield' => $this->average($group['yields']),
                'medianYield' => $this->median($group['yields']),
            ];
        }

        return ['points' => $points, 'bands' => $bands];
    }

    /**
     * Return the most recent slice in chronological order so a chart reads left to right.
     *
     * @return list<array{
     *   id:int,pressedAt:string,material:string,strains:string,temperatureC:float,
     *   yieldPercentage:float,passCount:int
     * }>
     */
    public function yieldSequence(
        ?string $material = null,
        ?string $strain = null,
        ?int $limit = null,
    ): array {
        $rows = array_reverse($this->observations($material, $strain, $limit, true));

        return array_map(static fn (array $batch): array => [
            'id' => $batch['id'],
            'pressedAt' => $batch['pressedAt'],
            'material' => $batch['material'],
            'strains' => $batch['strains'],
            'temperatureC' => $batch['temperatureC'],
            'yieldPercentage' => $batch['yieldPercentage'],
            'passCount' => $batch['passCount'],
        ], $rows);
    }

    /**
     * @return list<array{
     *   id:int,pressedAt:string,material:string,strains:string,startAmountG:float,
     *   yieldAmountG:float,yieldPercentage:float,temperatureC:float,passCount:int
     * }>
     */
    public function batchComparison(
        ?string $material = null,
        ?string $strain = null,
        int $limit = 100,
    ): array {
        return array_map(static fn (array $batch): array => [
            'id' => $batch['id'],
            'pressedAt' => $batch['pressedAt'],
            'material' => $batch['material'],
            'strains' => $batch['strains'],
            'startAmountG' => $batch['startAmountG'],
            'yieldAmountG' => $batch['yieldAmountG'],
            'yieldPercentage' => $batch['yieldPercentage'],
            'temperatureC' => $batch['temperatureC'],
            'passCount' => $batch['passCount'],
        ], $this->observations($material, $strain, $limit));
    }

    /**
     * Fetch one row per batch. Strains and passes are correlated subqueries so
     * neither child table can multiply a batch's yield observation.
     *
     * @return list<array{
     *   id:int,pressedAt:string,material:string,startAmountG:float,yieldAmountG:float,
     *   yieldPercentage:float,temperatureC:float,passCount:int,
     *   strainList:list<string>,strains:string
     * }>
     */
    private function observations(
        ?string $material = null,
        ?string $strain = null,
        ?int $limit = null,
        bool $byBatchNumber = false,
    ): array {
        $where = ['b.start_amount_g > 0'];
        $parameters = [];
        if ($material !== null && trim($material) !== '') {
            $where[] = 'TRIM(b.start_material) = TRIM(:material) COLLATE NOCASE';
            $parameters['material'] = trim($material);
        }
        if ($strain !== null && trim($strain) !== '') {
            $where[] = 'EXISTS ('
                . 'SELECT 1 FROM batch_strains AS selected_strain '
                . 'WHERE selected_strain.batch_id = b.id '
                . 'AND TRIM(selected_strain.name) = TRIM(:strain) COLLATE NOCASE'
                . ')';
            $parameters['strain'] = trim($strain);
        }

        $limitSql = '';
        if ($limit !== null) {
            $limitSql = ' LIMIT ' . max(1, min($limit, 500));
        }
        $statement = $this->database->prepare(
            'SELECT b.id, b.pressed_at, b.start_material, b.start_amount_g, b.yield_amount_g, '
            . 'first_pass.temperature_c AS first_temperature_c, '
            . '(SELECT COUNT(*) FROM batch_passes AS pass_count '
            . 'WHERE pass_count.batch_id = b.id) AS stored_pass_count, '
            . '(SELECT GROUP_CONCAT(ordered.name, ' . $this->database->quote(self::STRAIN_SEPARATOR) . ') '
            . 'FROM (SELECT strain.name FROM batch_strains AS strain '
            . 'WHERE strain.batch_id = b.id ORDER BY strain.position, strain.id) AS ordered'
            . ') AS strain_names '
            . 'FROM batches AS b '
            . 'INNER JOIN batch_passes AS first_pass '
            . 'ON first_pass.batch_id = b.id AND first_pass.position = 1 '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . ($byBatchNumber ? 'ORDER BY b.id DESC' : 'ORDER BY b.pressed_at DESC, b.id DESC')
            . $limitSql
        );
        $statement->execute($parameters);

        return array_map(static function (array $row): array {
            $startAmount = (float) $row['start_amount_g'];
            $yieldAmount = (float) $row['yield_amount_g'];
            $strainList = $row['strain_names'] === null || $row['strain_names'] === ''
                ? []
                : array_values(array_map(
                    'strval',
                    explode(self::STRAIN_SEPARATOR, (string) $row['strain_names']),
                ));
            return [
                'id' => (int) $row['id'],
                'pressedAt' => (string) $row['pressed_at'],
                'material' => trim((string) $row['start_material']),
                'startAmountG' => round($startAmount, 4),
                'yieldAmountG' => round($yieldAmount, 4),
                'yieldPercentage' => round(($yieldAmount / $startAmount) * 100.0, 2),
                'temperatureC' => round((float) $row['first_temperature_c'], 2),
                'passCount' => (int) $row['stored_pass_count'],
                'strainList' => $strainList,
                'strains' => implode(' • ', $strainList),
            ];
        }, array_values($statement->fetchAll()));
    }

    /**
     * @param list<array<string, mixed>> $batches
     * @param callable(array<string, mixed>):(?array{0:string,1:string}) $group
     * @return list<array<string, int|float|string>>
     */
    private function groupPerformance(array $batches, callable $group, string $labelKey): array
    {
        /** @var array<string, array{label:string,batches:list<array<string,mixed>>}> $groups */
        $groups = [];
        foreach ($batches as $batch) {
            $identity = $group($batch);
            if ($identity === null || $identity[0] === '') {
                continue;
            }
            [$key, $label] = $identity;
            $groups[$key] ??= ['label' => $label, 'batches' => []];
            $groups[$key]['batches'][] = $batch;
        }

        $rows = [];
        foreach ($groups as $grouped) {
            $metrics = $this->metrics($grouped['batches']);
            $rows[] = [
                $labelKey => $grouped['label'],
                'batchCount' => $metrics['totalBatches'],
                'averageYield' => $metrics['averageYield'],
                'medianYield' => $metrics['medianYield'],
                'overallYield' => $metrics['overallYield'],
                'bestYield' => $metrics['bestYield'],
            ];
        }

        usort($rows, static fn (array $left, array $right): int =>
            ($right['medianYield'] <=> $left['medianYield'])
            ?: ($right['batchCount'] <=> $left['batchCount'])
            ?: strcasecmp((string) $left[$labelKey], (string) $right[$labelKey]));

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $batches
     * @return array{
     *   totalBatches:int,averageYield:float,medianYield:float,yieldConsistency:float,overallYield:float,
     *   bestYield:float,totalInputG:float,totalYieldG:float
     * }
     */
    private function metrics(array $batches): array
    {
        if ($batches === []) {
            return [
                'totalBatches' => 0,
                'averageYield' => 0.0,
                'medianYield' => 0.0,
                'yieldConsistency' => 0.0,
                'overallYield' => 0.0,
                'bestYield' => 0.0,
                'totalInputG' => 0.0,
                'totalYieldG' => 0.0,
            ];
        }

        $yields = array_map(
            static fn (array $batch): float => (float) $batch['yieldPercentage'],
            $batches,
        );
        $totalInput = array_sum(array_map(
            static fn (array $batch): float => (float) $batch['startAmountG'],
            $batches,
        ));
        $totalYield = array_sum(array_map(
            static fn (array $batch): float => (float) $batch['yieldAmountG'],
            $batches,
        ));
        $medianYield = $this->median($yields);
        $absoluteDeviations = array_map(
            static fn (float $yield): float => abs($yield - $medianYield),
            $yields,
        );

        return [
            'totalBatches' => count($batches),
            'averageYield' => $this->average($yields),
            'medianYield' => $medianYield,
            'yieldConsistency' => $this->median($absoluteDeviations),
            'overallYield' => $totalInput > 0 ? round(($totalYield / $totalInput) * 100.0, 2) : 0.0,
            'bestYield' => round(max($yields), 2),
            'totalInputG' => round($totalInput, 4),
            'totalYieldG' => round($totalYield, 4),
        ];
    }

    /** @param list<float> $values */
    private function average(array $values): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 2);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        $median = count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2.0;
        return round($median, 2);
    }

    /** @return array{0:string,1:array<string,string>} */
    private function optionalMaterialAnd(?string $material, string $alias = ''): array
    {
        if ($material === null || trim($material) === '') {
            return ['', []];
        }
        $column = $alias === '' ? 'start_material' : $alias . '.start_material';
        return [
            ' AND TRIM(' . $column . ') = TRIM(:material) COLLATE NOCASE',
            ['material' => trim($material)],
        ];
    }
}
