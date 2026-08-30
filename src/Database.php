<?php

declare(strict_types=1);

namespace RosinTracker;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private const BASELINE_MIGRATION = '001_baseline.sql';
    private const UNSUPPORTED_MIGRATION_HISTORY =
        'The database contains migration history that is not supported by this release.';

    private ?PDO $connection = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $this->preparePrivateDirectory(dirname($this->config->databasePath));
        $this->preparePrivateDirectory($this->config->uploadPath);
        $this->preparePrivateDirectory($this->config->backupPath);

        $pdo = new PDO('sqlite:' . $this->config->databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        $this->migrate($pdo);
        @chmod($this->config->databasePath, 0660);

        $this->connection = $pdo;
        return $pdo;
    }

    private function migrate(PDO $pdo): void
    {
        $lockPath = dirname($this->config->databasePath) . DIRECTORY_SEPARATOR . '.migration.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('The database migration lock could not be acquired.');
        }

        try {
            $migrations = $this->loadMigrations();
            $trackingTableExists = (bool) $pdo->query(
                "SELECT EXISTS("
                . "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'"
                . ')'
            )->fetchColumn();
            $objectFilter = $trackingTableExists ? " AND name <> 'schema_migrations'" : '';
            $userObjectCount = (int) $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'" . $objectFilter
            )->fetchColumn();

            $applied = [];
            if ($trackingTableExists) {
                $this->assertMigrationTable($pdo);
                $applied = $pdo->query(
                    'SELECT migration, checksum FROM schema_migrations ORDER BY migration'
                )->fetchAll(PDO::FETCH_ASSOC);
                if ($applied === [] && $userObjectCount !== 0) {
                    throw new RuntimeException(self::UNSUPPORTED_MIGRATION_HISTORY);
                }
            } elseif ($userObjectCount !== 0) {
                throw new RuntimeException(self::UNSUPPORTED_MIGRATION_HISTORY);
            }

            if (count($applied) > count($migrations)) {
                throw new RuntimeException(self::UNSUPPORTED_MIGRATION_HISTORY);
            }
            foreach ($applied as $index => $row) {
                $expected = $migrations[$index];
                if ((string) $row['migration'] !== $expected['migration']) {
                    throw new RuntimeException(self::UNSUPPORTED_MIGRATION_HISTORY);
                }
                if (!hash_equals((string) $row['checksum'], $expected['checksum'])) {
                    throw new RuntimeException('An applied migration has changed: ' . $expected['migration']);
                }
            }

            for ($index = count($applied); $index < count($migrations); $index++) {
                $migration = $migrations[$index];
                $pdo->beginTransaction();
                try {
                    if (!$trackingTableExists) {
                        $pdo->exec(
                            'CREATE TABLE schema_migrations ('
                            . 'migration TEXT PRIMARY KEY, checksum TEXT NOT NULL, applied_at TEXT NOT NULL'
                            . ')'
                        );
                        $trackingTableExists = true;
                    }
                    $pdo->exec($migration['sql']);
                    $recordStatement = $pdo->prepare(
                        'INSERT INTO schema_migrations (migration, checksum, applied_at) '
                        . 'VALUES (:migration, :checksum, :applied_at)'
                    );
                    $recordStatement->execute([
                        'migration' => $migration['migration'],
                        'checksum' => $migration['checksum'],
                        'applied_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                    ]);
                    $pdo->commit();
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $error;
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @chmod($lockPath, 0660);
        }
    }

    /** @return list<array{migration: string, sql: string, checksum: string}> */
    private function loadMigrations(): array
    {
        $migrationFiles = glob(
            $this->config->basePath . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.sql'
        );
        if ($migrationFiles === false) {
            throw new RuntimeException('The migration directory could not be read.');
        }
        sort($migrationFiles, SORT_STRING);
        if ($migrationFiles === [] || basename($migrationFiles[0]) !== self::BASELINE_MIGRATION) {
            throw new RuntimeException('The consolidated database baseline is missing.');
        }

        $migrations = [];
        foreach ($migrationFiles as $migrationFile) {
            $migration = basename($migrationFile);
            $sql = file_get_contents($migrationFile);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Migration is empty or unreadable: ' . $migration);
            }
            $migrations[] = [
                'migration' => $migration,
                'sql' => $sql,
                'checksum' => hash('sha256', $sql),
            ];
        }
        return $migrations;
    }

    private function assertMigrationTable(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(schema_migrations)')->fetchAll(PDO::FETCH_ASSOC);
        if (count($columns) !== 3
            || (string) $columns[0]['name'] !== 'migration'
            || strtoupper((string) $columns[0]['type']) !== 'TEXT'
            || (int) $columns[0]['pk'] !== 1
            || (string) $columns[1]['name'] !== 'checksum'
            || strtoupper((string) $columns[1]['type']) !== 'TEXT'
            || (int) $columns[1]['notnull'] !== 1
            || (string) $columns[2]['name'] !== 'applied_at'
            || strtoupper((string) $columns[2]['type']) !== 'TEXT'
            || (int) $columns[2]['notnull'] !== 1) {
            throw new RuntimeException(self::UNSUPPORTED_MIGRATION_HISTORY);
        }
    }

    private function preparePrivateDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException('Private application directory could not be created: ' . $path);
        }

        if (!is_writable($path)) {
            throw new RuntimeException('Private application directory is not writable: ' . $path);
        }
    }
}
