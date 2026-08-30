<?php

declare(strict_types=1);

use RosinTracker\Database;

$config = require dirname(__DIR__) . '/src/bootstrap.php';

$failures = [];
if (PHP_VERSION_ID < 80400) {
    $failures[] = 'PHP 8.4 or newer is required.';
}
foreach ([
    'curl', 'fileinfo', 'gd', 'iconv', 'intl', 'json', 'mbstring',
    'openssl', 'pdo_sqlite', 'session', 'sodium', 'sqlite3', 'zip',
] as $extension) {
    if (!extension_loaded($extension)) {
        $failures[] = 'Missing PHP extension: ' . $extension;
    }
}
if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    $failures[] = 'Composer dependencies are missing: vendor/autoload.php was not found.';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    exit(1);
}

try {
    $database = (new Database($config))->connection();
    $integrity = $database->query('PRAGMA integrity_check')->fetchColumn();
    if ($integrity !== 'ok') {
        throw new RuntimeException('SQLite integrity check did not return ok.');
    }
    if ($database->query('PRAGMA foreign_key_check')->fetch() !== false) {
        throw new RuntimeException('SQLite foreign-key check found a violation.');
    }
    $migrationCount = (int) $database->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    printf("PHP %s ready; SQLite integrity verified; %d migration(s) applied.\n", PHP_VERSION, $migrationCount);
} catch (Throwable $error) {
    fwrite(STDERR, 'Runtime check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
