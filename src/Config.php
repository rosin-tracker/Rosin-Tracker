<?php

declare(strict_types=1);

namespace RosinTracker;

final readonly class Config
{
    public function __construct(
        public string $basePath,
        public string $dataRoot,
        public string $databasePath,
        public string $uploadPath,
        public string $backupPath,
        public string $securityKeyPath,
        public string $externalUrl,
        public bool $externalUrlManaged,
        public string $timezone,
        public int $maximumPhotoBytes,
    ) {
    }

    public static function fromEnvironment(string $basePath): self
    {
        $configuredRoot = self::environment('ROSIN_TRACKER_DATA_DIR');
        $systemRoot = '/var/lib/rosin-tracker-php';
        $dataRoot = $configuredRoot
            ?? (is_dir($systemRoot) ? $systemRoot : $basePath . DIRECTORY_SEPARATOR . 'var');

        $externalUrl = self::environment('ROSIN_TRACKER_EXTERNAL_URL');

        return new self(
            basePath: $basePath,
            dataRoot: $dataRoot,
            databasePath: $dataRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'rosin-tracker.sqlite',
            uploadPath: $dataRoot . DIRECTORY_SEPARATOR . 'uploads',
            backupPath: $dataRoot . DIRECTORY_SEPARATOR . 'backups',
            securityKeyPath: self::environment('ROSIN_TRACKER_SECURITY_KEY_FILE')
                ?? $dataRoot . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'auth.key',
            externalUrl: rtrim($externalUrl ?? 'http://192.168.1.15:5000', '/'),
            externalUrlManaged: $externalUrl !== null,
            timezone: self::environment('ROSIN_TRACKER_TIMEZONE') ?? 'Europe/Copenhagen',
            maximumPhotoBytes: 24 * 1024 * 1024,
        );
    }

    private static function environment(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
