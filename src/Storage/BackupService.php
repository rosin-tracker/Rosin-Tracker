<?php

declare(strict_types=1);

namespace RosinTracker\Storage;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RosinTracker\Config;
use RuntimeException;
use Throwable;
use ZipArchive;

final readonly class BackupService
{
    public function __construct(
        private Config $config,
        private PDO $database,
    ) {
    }

    /** @return array{filename: string, path: string, byteSize: int, createdAt: string} */
    public function create(): array
    {
        $createdAt = gmdate('Y-m-d\\TH:i:s\\Z');
        $identifier = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $filename = 'rosin-tracker-backup-' . $identifier . '.zip';
        $finalPath = $this->config->backupPath . DIRECTORY_SEPARATOR . $filename;
        $partialPath = $finalPath . '.part';
        $workDirectory = $this->config->backupPath . DIRECTORY_SEPARATOR . '.building-' . $identifier;

        if (!mkdir($workDirectory, 0700) && !is_dir($workDirectory)) {
            throw new RuntimeException('The private backup work directory could not be created.');
        }

        try {
            $databaseCopy = $workDirectory . DIRECTORY_SEPARATOR . 'rosin-tracker.sqlite';
            $quotedPath = $this->database->quote($databaseCopy);
            if (!is_string($quotedPath)) {
                throw new RuntimeException('The backup database path could not be prepared.');
            }
            $this->database->exec('VACUUM INTO ' . $quotedPath);
            $this->verifyDatabaseCopy($databaseCopy);

            $files = [[
                'source' => $databaseCopy,
                'archive' => 'database/rosin-tracker.sqlite',
            ]];
            foreach ($this->stageUploadFiles($databaseCopy, $workDirectory) as $upload) {
                $files[] = $upload;
            }

            $manifestFiles = [];
            foreach ($files as $file) {
                $hash = hash_file('sha256', $file['source']);
                $size = filesize($file['source']);
                if (!is_string($hash) || $size === false) {
                    throw new RuntimeException('A backup file could not be fingerprinted.');
                }
                $manifestFiles[] = [
                    'path' => $file['archive'],
                    'byteSize' => $size,
                    'sha256' => $hash,
                ];
            }

            $migrations = $this->database
                ->query('SELECT migration FROM schema_migrations ORDER BY migration')
                ->fetchAll(PDO::FETCH_COLUMN);
            $manifest = [
                'format' => 'rosin-tracker-backup',
                'version' => 1,
                'createdAt' => $createdAt,
                'scope' => [
                    'owner', 'authentication_configuration', 'settings',
                    'batches', 'presets', 'templates', 'photos',
                    'legacy_import_provenance',
                ],
                'excluded' => [
                    'authentication_encryption_key', 'curing_logs',
                    'curing_reminders',
                ],
                'migrations' => array_values(array_map('strval', $migrations)),
                'files' => $manifestFiles,
            ];
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $archive = new ZipArchive();
            if ($archive->open($partialPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('The backup archive could not be created.');
            }
            try {
                foreach ($files as $file) {
                    if (!$archive->addFile($file['source'], $file['archive'])) {
                        throw new RuntimeException('A private file could not be added to the backup archive.');
                    }
                }
                if (!$archive->addFromString('manifest.json', $manifestJson . "\n")) {
                    throw new RuntimeException('The backup manifest could not be added.');
                }
            } catch (Throwable $error) {
                $archive->close();
                throw $error;
            }
            if (!$archive->close()) {
                throw new RuntimeException('The backup archive could not be finalized.');
            }

            if (!is_file($partialPath) || filesize($partialPath) === 0) {
                throw new RuntimeException('The completed backup archive is empty.');
            }
            $this->verifyArchive($partialPath, $manifestFiles, $manifestJson . "\n");
            chmod($partialPath, 0640);
            if (!rename($partialPath, $finalPath)) {
                throw new RuntimeException('The completed backup archive could not be committed.');
            }

            return [
                'filename' => $filename,
                'path' => $finalPath,
                'byteSize' => (int) filesize($finalPath),
                'createdAt' => $createdAt,
            ];
        } finally {
            @unlink($partialPath);
            $this->removeWorkDirectory($workDirectory);
        }
    }

    /** @return list<array{filename: string, path: string, byteSize: int, modifiedAt: int}> */
    public function available(): array
    {
        $paths = glob($this->config->backupPath . DIRECTORY_SEPARATOR . 'rosin-tracker-backup-*.zip');
        if ($paths === false) {
            return [];
        }

        $backups = [];
        foreach ($paths as $path) {
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $size = filesize($path);
            $modified = filemtime($path);
            if ($size === false || $modified === false) {
                continue;
            }
            $backups[] = [
                'filename' => basename($path),
                'path' => $path,
                'byteSize' => $size,
                'modifiedAt' => $modified,
            ];
        }

        usort($backups, static fn (array $left, array $right): int => $right['modifiedAt'] <=> $left['modifiedAt']);
        return $backups;
    }

    public function find(string $filename): ?string
    {
        if (preg_match('/^rosin-tracker-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\\.zip$/', $filename) !== 1) {
            return null;
        }
        $path = $this->config->backupPath . DIRECTORY_SEPARATOR . $filename;
        return is_file($path) && !is_link($path) ? $path : null;
    }

    private function verifyDatabaseCopy(string $path): void
    {
        $copy = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $integrity = $copy->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            throw new RuntimeException('The SQLite backup copy failed its integrity check.');
        }
        $foreignKeyFailure = $copy->query('PRAGMA foreign_key_check')->fetch();
        if ($foreignKeyFailure !== false) {
            throw new RuntimeException('The SQLite backup copy contains a foreign-key violation.');
        }
    }

    /** @return list<array{source: string, archive: string}> */
    private function stageUploadFiles(string $databaseCopy, string $workDirectory): array
    {
        $copy = new PDO('sqlite:' . $databaseCopy, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $storageNames = $copy->query(
            'SELECT storage_name FROM batch_photos ORDER BY batch_id, position, id'
        )->fetchAll(PDO::FETCH_COLUMN);
        $uploadRoot = realpath($this->config->uploadPath);
        if ($uploadRoot === false || is_link($uploadRoot)) {
            throw new RuntimeException('Private photograph storage is unavailable for backup.');
        }
        $files = [];
        foreach ($storageNames as $storageName) {
            $relative = (string) $storageName;
            if (preg_match('#^[1-9][0-9]*/[a-f0-9]{40}\\.(?:jpg|png|webp)$#', $relative) !== 1) {
                throw new RuntimeException('The backup database contains an invalid photograph path.');
            }
            $source = $uploadRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $sourceDirectory = dirname($source);
            $resolvedDirectory = realpath($sourceDirectory);
            if (is_link($sourceDirectory)
                || $resolvedDirectory === false
                || dirname($resolvedDirectory) !== $uploadRoot
                || !is_file($source)
                || is_link($source)) {
                throw new RuntimeException('A photograph referenced by the backup database is unavailable.');
            }
            $staged = $workDirectory . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $stagedDirectory = dirname($staged);
            if (!is_dir($stagedDirectory)
                && !mkdir($stagedDirectory, 0700, true)
                && !is_dir($stagedDirectory)) {
                throw new RuntimeException('A photograph could not be staged for backup.');
            }
            if (!copy($source, $staged) || !is_file($staged)) {
                throw new RuntimeException('A photograph changed while the backup was being created.');
            }
            chmod($staged, 0600);
            $files[] = ['source' => $staged, 'archive' => 'uploads/' . $relative];
        }
        return $files;
    }

    /**
     * @param list<array{path:string,byteSize:int,sha256:string}> $manifestFiles
     */
    private function verifyArchive(string $path, array $manifestFiles, string $manifestJson): void
    {
        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('The completed backup archive failed its consistency check.');
        }

        try {
            if ($archive->numFiles !== count($manifestFiles) + 1
                || $archive->getFromName('manifest.json') !== $manifestJson) {
                throw new RuntimeException('The completed backup manifest could not be verified.');
            }
            foreach ($manifestFiles as $file) {
                $stat = $archive->statName($file['path']);
                $stream = $archive->getStream($file['path']);
                if (!is_array($stat) || (int) ($stat['size'] ?? -1) !== $file['byteSize'] || $stream === false) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    throw new RuntimeException('A completed backup file could not be verified.');
                }
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stream);
                fclose($stream);
                if ($bytes !== $file['byteSize'] || !hash_equals($file['sha256'], hash_final($hash))) {
                    throw new RuntimeException('A completed backup file failed its checksum verification.');
                }
            }
        } finally {
            if (!$archive->close()) {
                throw new RuntimeException('The verified backup archive could not be closed.');
            }
        }
    }

    private function removeWorkDirectory(string $directory): void
    {
        $expectedPrefix = $this->config->backupPath . DIRECTORY_SEPARATOR . '.building-';
        if (!str_starts_with($directory, $expectedPrefix) || !is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
