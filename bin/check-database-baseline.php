<?php

declare(strict_types=1);

use RosinTracker\Config;
use RosinTracker\Database;

require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/Database.php';

const BASELINE_MIGRATION = '001_baseline.sql';
const UNSUPPORTED_HISTORY =
    'The database contains migration history that is not supported by this release.';
const EXPECTED_TABLES = [
    'authentication_login_throttle',
    'authentication_sensitive_throttle',
    'authentication_state',
    'batch_bags',
    'batch_passes',
    'batch_photos',
    'batch_strains',
    'batch_templates',
    'batches',
    'legacy_v1_imports',
    'oidc_provider_config',
    'owner_accounts',
    'owner_oidc_identity',
    'owner_recovery_codes',
    'owner_totp',
    'preset_options',
    'schema_migrations',
    'settings',
];
const EXPECTED_BATCH_COLUMNS = [
    'id',
    'pressed_at',
    'start_material',
    'start_amount_g',
    'yield_amount_g',
    'press_capacity_tons',
    'humidity_percent',
    'notes',
    'source_template_id',
    'created_at',
    'updated_at',
];
const EXPECTED_INDEXES = [
    'batch_strains_name_index',
    'batches_material_index',
    'batches_pressed_at_index',
    'owner_recovery_codes_available_index',
    'preset_options_field_index',
];

$temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'rosin-tracker-baseline-' . bin2hex(random_bytes(6));
$basePath = dirname(__DIR__);

try {
    $freshConfig = testConfig($basePath, $temporaryRoot . '/fresh');
    $database = new Database($freshConfig);
    $pdo = $database->connection();
    assertBaseline($pdo, $basePath, 'fresh database');
    assertPresetCounts($pdo, 'fresh database');
    $firstAppliedAt = (string) $pdo->query(
        "SELECT applied_at FROM schema_migrations WHERE migration = '" . BASELINE_MIGRATION . "'"
    )->fetchColumn();
    unset($pdo, $database);

    $reopenedDatabase = new Database($freshConfig);
    $reopened = $reopenedDatabase->connection();
    assertBaseline($reopened, $basePath, 'reopened database');
    assertPresetCounts($reopened, 'reopened database');
    $reopenedAppliedAt = (string) $reopened->query(
        "SELECT applied_at FROM schema_migrations WHERE migration = '" . BASELINE_MIGRATION . "'"
    )->fetchColumn();
    if ($firstAppliedAt === '' || $reopenedAppliedAt !== $firstAppliedAt) {
        throw new RuntimeException('Reopening the database changed the baseline application timestamp.');
    }
    unset($reopened, $reopenedDatabase);

    testWrongChecksum($basePath, $temporaryRoot . '/wrong-checksum');
    testAdditionalHistory($basePath, $temporaryRoot . '/additional-history');
    testUnknownOnlyHistory($basePath, $temporaryRoot . '/unknown-history');
    testUntrackedSchema($basePath, $temporaryRoot . '/untracked-schema');
    testEmptyHistoryWithSchema($basePath, $temporaryRoot . '/empty-history');
    testMalformedHistoryTable($basePath, $temporaryRoot . '/malformed-history');
    testMissingBaseline($temporaryRoot . '/missing-baseline-app', $temporaryRoot . '/missing-baseline-data');
    testEmptyBaseline($temporaryRoot . '/empty-baseline-app', $temporaryRoot . '/empty-baseline-data');
    testFailedBaselineRollback($temporaryRoot . '/failed-baseline-app', $temporaryRoot . '/failed-baseline-data');

    fwrite(
        STDOUT,
        "Database baseline check passed: fresh install, idempotent reopen, schema cleanup, "
        . "checksum protection, and unsupported-history rejection verified.\n"
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Database baseline check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    removeTemporaryRoot($temporaryRoot);
}

function testConfig(string $basePath, string $dataRoot): Config
{
    return new Config(
        basePath: $basePath,
        dataRoot: $dataRoot,
        databasePath: $dataRoot . '/database/rosin-tracker.sqlite',
        uploadPath: $dataRoot . '/uploads',
        backupPath: $dataRoot . '/backups',
        securityKeyPath: $dataRoot . '/security/auth.key',
        externalUrl: 'https://rosin-tracker.test',
        externalUrlManaged: false,
        timezone: 'Europe/Copenhagen',
        maximumPhotoBytes: 24 * 1024 * 1024,
    );
}

function assertBaseline(PDO $pdo, string $basePath, string $context): void
{
    $migrations = $pdo->query(
        'SELECT migration, checksum FROM schema_migrations ORDER BY migration'
    )->fetchAll(PDO::FETCH_ASSOC);
    $expectedChecksum = hash_file('sha256', $basePath . '/migrations/' . BASELINE_MIGRATION);
    if (!is_string($expectedChecksum) || $migrations !== [[
        'migration' => BASELINE_MIGRATION,
        'checksum' => $expectedChecksum,
    ]]) {
        throw new RuntimeException(ucfirst($context) . ' did not record exactly the current baseline.');
    }

    $tables = array_map(
        'strval',
        $pdo->query(
            "SELECT name FROM sqlite_master "
            . "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN),
    );
    if ($tables !== EXPECTED_TABLES) {
        throw new RuntimeException(ucfirst($context) . ' did not create exactly the current table set.');
    }

    $batchColumns = array_map(
        static fn (array $column): string => (string) $column['name'],
        $pdo->query('PRAGMA table_info(batches)')->fetchAll(PDO::FETCH_ASSOC),
    );
    if ($batchColumns !== EXPECTED_BATCH_COLUMNS) {
        throw new RuntimeException(ucfirst($context) . ' contains an unexpected batches schema.');
    }

    $indexes = array_map(
        'strval',
        $pdo->query(
            "SELECT name FROM sqlite_master "
            . "WHERE type = 'index' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN),
    );
    if ($indexes !== EXPECTED_INDEXES) {
        throw new RuntimeException(ucfirst($context) . ' contains an unexpected or redundant index.');
    }

    if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
        || $pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
        throw new RuntimeException(ucfirst($context) . ' failed SQLite integrity validation.');
    }
}

function assertPresetCounts(PDO $pdo, string $context): void
{
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM preset_options WHERE field_key = 'start_material'"
    )->fetchColumn() !== 4
        || (int) $pdo->query(
            "SELECT COUNT(*) FROM preset_options WHERE field_key = 'bag'"
        )->fetchColumn() !== 6
        || (int) $pdo->query(
            "SELECT COUNT(*) FROM preset_options WHERE field_key LIKE 'legacy_%'"
        )->fetchColumn() !== 0) {
        throw new RuntimeException(ucfirst($context) . ' does not contain exactly the current default presets.');
    }
}

function testWrongChecksum(string $basePath, string $dataRoot): void
{
    $config = testConfig($basePath, $dataRoot);
    createValidDatabase($config);
    $pdo = rawConnection($config->databasePath);
    $pdo->exec("UPDATE schema_migrations SET checksum = '" . str_repeat('0', 64) . "'");
    $before = migrationRows($pdo);
    unset($pdo);

    expectDatabaseFailure(
        $config,
        'An applied migration has changed: ' . BASELINE_MIGRATION,
        'changed baseline checksum',
    );
    assertMigrationRowsUnchanged($config->databasePath, $before, 'changed baseline checksum');
}

function testAdditionalHistory(string $basePath, string $dataRoot): void
{
    $config = testConfig($basePath, $dataRoot);
    createValidDatabase($config);
    $pdo = rawConnection($config->databasePath);
    $statement = $pdo->prepare(
        'INSERT INTO schema_migrations (migration, checksum, applied_at) VALUES (?, ?, ?)'
    );
    $statement->execute(['999_unavailable.sql', str_repeat('1', 64), '2026-08-30T00:00:00Z']);
    $before = migrationRows($pdo);
    unset($statement, $pdo);

    expectDatabaseFailure($config, UNSUPPORTED_HISTORY, 'additional migration history');
    assertMigrationRowsUnchanged($config->databasePath, $before, 'additional migration history');
}

function testUnknownOnlyHistory(string $basePath, string $dataRoot): void
{
    $config = testConfig($basePath, $dataRoot);
    createValidDatabase($config);
    $pdo = rawConnection($config->databasePath);
    $pdo->exec('DELETE FROM schema_migrations');
    $statement = $pdo->prepare(
        'INSERT INTO schema_migrations (migration, checksum, applied_at) VALUES (?, ?, ?)'
    );
    $statement->execute(['999_unavailable.sql', str_repeat('2', 64), '2026-08-30T00:00:00Z']);
    $before = migrationRows($pdo);
    unset($statement, $pdo);

    expectDatabaseFailure($config, UNSUPPORTED_HISTORY, 'unknown migration history');
    assertMigrationRowsUnchanged($config->databasePath, $before, 'unknown migration history');
}

function testUntrackedSchema(string $basePath, string $dataRoot): void
{
    $config = testConfig($basePath, $dataRoot);
    $pdo = rawConnection($config->databasePath);
    $pdo->exec('CREATE TABLE untracked_data (id INTEGER PRIMARY KEY)');
    unset($pdo);

    expectDatabaseFailure($config, UNSUPPORTED_HISTORY, 'untracked existing schema');
    $pdo = rawConnection($config->databasePath);
    if (tableExists($pdo, 'schema_migrations') || !tableExists($pdo, 'untracked_data')) {
        throw new RuntimeException('Rejecting an untracked schema changed the database.');
    }
}

function testEmptyHistoryWithSchema(string $basePath, string $dataRoot): void
{
    $config = testConfig($basePath, $dataRoot);
    $pdo = rawConnection($config->databasePath);
    createMigrationTable($pdo);
    $pdo->exec('CREATE TABLE untracked_data (id INTEGER PRIMARY KEY)');
    unset($pdo);

    expectDatabaseFailure($config, UNSUPPORTED_HISTORY, 'empty history over an existing schema');
    $pdo = rawConnection($config->databasePath);
    if ((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() !== 0
        || !tableExists($pdo, 'untracked_data')) {
        throw new RuntimeException('Rejecting empty migration history changed the database.');
    }
}

function testMalformedHistoryTable(string $basePath, string $dataRoot): void
{
    $config = testConfig($basePath, $dataRoot);
    $pdo = rawConnection($config->databasePath);
    $pdo->exec('CREATE TABLE schema_migrations (migration TEXT PRIMARY KEY)');
    unset($pdo);

    expectDatabaseFailure($config, UNSUPPORTED_HISTORY, 'malformed migration table');
    $pdo = rawConnection($config->databasePath);
    $columns = $pdo->query('PRAGMA table_info(schema_migrations)')->fetchAll(PDO::FETCH_ASSOC);
    if (count($columns) !== 1 || (string) $columns[0]['name'] !== 'migration') {
        throw new RuntimeException('Rejecting a malformed migration table changed the database.');
    }
}

function testMissingBaseline(string $appRoot, string $dataRoot): void
{
    if (!mkdir($appRoot . '/migrations', 0770, true) && !is_dir($appRoot . '/migrations')) {
        throw new RuntimeException('Could not prepare the missing-baseline test.');
    }
    $config = testConfig($appRoot, $dataRoot);
    expectDatabaseFailure($config, 'The consolidated database baseline is missing.', 'missing baseline');

    $pdo = rawConnection($config->databasePath);
    if (tableExists($pdo, 'schema_migrations')) {
        throw new RuntimeException('A missing baseline created migration history.');
    }
}

function testEmptyBaseline(string $appRoot, string $dataRoot): void
{
    if (!mkdir($appRoot . '/migrations', 0770, true) && !is_dir($appRoot . '/migrations')) {
        throw new RuntimeException('Could not prepare the empty-baseline test.');
    }
    $baselinePath = $appRoot . '/migrations/' . BASELINE_MIGRATION;
    if (file_put_contents($baselinePath, "\n") === false) {
        throw new RuntimeException('Could not create the empty-baseline fixture.');
    }
    $config = testConfig($appRoot, $dataRoot);
    expectDatabaseFailure(
        $config,
        'Migration is empty or unreadable: ' . BASELINE_MIGRATION,
        'empty baseline',
    );

    $pdo = rawConnection($config->databasePath);
    if (tableExists($pdo, 'schema_migrations')) {
        throw new RuntimeException('An empty baseline created migration history.');
    }
}

function testFailedBaselineRollback(string $appRoot, string $dataRoot): void
{
    if (!mkdir($appRoot . '/migrations', 0770, true) && !is_dir($appRoot . '/migrations')) {
        throw new RuntimeException('Could not prepare the failed-baseline test.');
    }
    $baselinePath = $appRoot . '/migrations/' . BASELINE_MIGRATION;
    $invalidSql = "CREATE TABLE partial_install (id INTEGER PRIMARY KEY);\n"
        . "THIS IS NOT VALID SQL;\n";
    if (file_put_contents($baselinePath, $invalidSql) === false) {
        throw new RuntimeException('Could not create the failed-baseline fixture.');
    }
    $config = testConfig($appRoot, $dataRoot);
    expectAnyDatabaseFailure($config, 'invalid baseline SQL');

    $pdo = rawConnection($config->databasePath);
    if (tableExists($pdo, 'schema_migrations') || tableExists($pdo, 'partial_install')) {
        throw new RuntimeException('A failed baseline was not rolled back atomically.');
    }
}

function createValidDatabase(Config $config): void
{
    $database = new Database($config);
    $pdo = $database->connection();
    unset($pdo, $database);
}

function expectDatabaseFailure(Config $config, string $expectedMessage, string $context): void
{
    try {
        $database = new Database($config);
        $pdo = $database->connection();
        unset($pdo, $database);
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== $expectedMessage) {
            throw new RuntimeException(
                ucfirst($context) . ' failed with an unexpected message: ' . $error->getMessage(),
            );
        }
        return;
    }

    throw new RuntimeException(ucfirst($context) . ' was accepted unexpectedly.');
}

function expectAnyDatabaseFailure(Config $config, string $context): void
{
    try {
        $database = new Database($config);
        $pdo = $database->connection();
        unset($pdo, $database);
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException(ucfirst($context) . ' was accepted unexpectedly.');
}

function rawConnection(string $databasePath): PDO
{
    $directory = dirname($databasePath);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not prepare a test database directory.');
    }
    return new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function createMigrationTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE schema_migrations ('
        . 'migration TEXT PRIMARY KEY, checksum TEXT NOT NULL, applied_at TEXT NOT NULL'
        . ')'
    );
}

/** @return list<array{migration: string, checksum: string, applied_at: string}> */
function migrationRows(PDO $pdo): array
{
    return $pdo->query(
        'SELECT migration, checksum, applied_at FROM schema_migrations ORDER BY migration'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** @param list<array{migration: string, checksum: string, applied_at: string}> $expected */
function assertMigrationRowsUnchanged(string $databasePath, array $expected, string $context): void
{
    $pdo = rawConnection($databasePath);
    if (migrationRows($pdo) !== $expected) {
        throw new RuntimeException(ucfirst($context) . ' changed migration history while rejecting it.');
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        "SELECT EXISTS(SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?)"
    );
    $statement->execute([$table]);
    return (bool) $statement->fetchColumn();
}

function removeTemporaryRoot(string $root): void
{
    $expectedPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'rosin-tracker-baseline-';
    if (!str_starts_with($root, $expectedPrefix) || !is_dir($root) || is_link($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($root);
}
