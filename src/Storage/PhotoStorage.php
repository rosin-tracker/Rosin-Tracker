<?php

declare(strict_types=1);

namespace RosinTracker\Storage;

use GdImage;
use RosinTracker\Config;

final readonly class PhotoStorage
{
    private const MAXIMUM_PIXELS = 24_000_000;
    private const MAXIMUM_DIMENSION = 12_000;
    private const NORMALIZED_DIMENSION = 4_096;

    public function __construct(private Config $config)
    {
    }

    /**
     * @param array<string, mixed> $fileBag
     * @return list<array{tmp_path: string, original_name: string, mime_type: string, byte_size: int}>
     */
    public function validateUploadBag(array $fileBag, int $remainingSlots): array
    {
        if ($fileBag === [] || !isset($fileBag['error'])) {
            return [];
        }

        $errors = is_array($fileBag['error']) ? array_values($fileBag['error']) : [$fileBag['error']];
        $names = is_array($fileBag['name'] ?? null) ? array_values($fileBag['name']) : [$fileBag['name'] ?? ''];
        $paths = is_array($fileBag['tmp_name'] ?? null) ? array_values($fileBag['tmp_name']) : [$fileBag['tmp_name'] ?? ''];
        $sizes = is_array($fileBag['size'] ?? null) ? array_values($fileBag['size']) : [$fileBag['size'] ?? 0];

        $uploads = [];
        $totalBytes = 0;
        foreach ($errors as $index => $error) {
            $errorCode = (int) $error;
            if ($errorCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errorCode !== UPLOAD_ERR_OK) {
                throw new PhotoUploadException($this->uploadErrorMessage($errorCode));
            }
            if (count($uploads) >= $remainingSlots) {
                throw new PhotoUploadException('A batch can contain no more than five photographs.');
            }

            $temporaryPath = (string) ($paths[$index] ?? '');
            $reportedSize = (int) ($sizes[$index] ?? 0);
            if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
                throw new PhotoUploadException('A photograph did not arrive as a valid HTTP upload.');
            }
            $actualSize = filesize($temporaryPath);
            if ($actualSize === false || $actualSize < 1 || $actualSize !== $reportedSize) {
                throw new PhotoUploadException('A photograph has an invalid size.');
            }
            $totalBytes += $actualSize;
            if ($totalBytes > $this->config->maximumPhotoBytes * 5) {
                throw new PhotoUploadException('The selected photographs are too large in total.');
            }
            $uploads[] = $this->inspectFile(
                $temporaryPath,
                (string) ($names[$index] ?? 'photo'),
                $actualSize,
            );
        }

        return $uploads;
    }

    /**
     * Validate a regular file produced by a trusted CLI workflow. Unlike an
     * HTTP upload this intentionally does not call is_uploaded_file().
     *
     * @return array{tmp_path: string, original_name: string, mime_type: string, byte_size: int}
     */
    public function validateTrustedCliFile(string $path, string $originalName): array
    {
        if (PHP_SAPI !== 'cli' || !is_file($path) || is_link($path)) {
            throw new PhotoUploadException('A trusted photograph file is unavailable.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1) {
            throw new PhotoUploadException('A photograph has an invalid size.');
        }

        return $this->inspectFile($path, $originalName, $size);
    }

    /**
     * @param array{tmp_path: string, original_name: string, mime_type: string, byte_size: int} $upload
     * @return array{storage_name: string, original_name: string, mime_type: string, byte_size: int}
     */
    public function store(int $batchId, array $upload): array
    {
        $source = $this->decode($upload['tmp_path'], $upload['mime_type']);
        $source = $this->orient($source, $upload['tmp_path'], $upload['mime_type']);
        $normalized = $this->resizeIfNeeded($source);
        if ($normalized !== $source) {
            imagedestroy($source);
        }

        $extension = match ($upload['mime_type']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        };
        $relativeDirectory = (string) $batchId;
        $root = realpath($this->config->uploadPath);
        if ($root === false || is_link($root)) {
            imagedestroy($normalized);
            throw new PhotoUploadException('Private photograph storage is unavailable.');
        }
        $absoluteDirectory = $root . DIRECTORY_SEPARATOR . $relativeDirectory;
        if (is_link($absoluteDirectory)) {
            imagedestroy($normalized);
            throw new PhotoUploadException('The private photograph directory is unsafe.');
        }
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
            imagedestroy($normalized);
            throw new PhotoUploadException('The private photograph directory could not be created.');
        }
        $resolvedDirectory = realpath($absoluteDirectory);
        if ($resolvedDirectory === false || dirname($resolvedDirectory) !== $root) {
            imagedestroy($normalized);
            throw new PhotoUploadException('The private photograph directory is outside storage.');
        }

        $filename = bin2hex(random_bytes(20)) . '.' . $extension;
        $storageName = $relativeDirectory . '/' . $filename;
        $finalPath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
        $temporaryPath = $finalPath . '.part';

        $written = match ($upload['mime_type']) {
            'image/jpeg' => imagejpeg($normalized, $temporaryPath, 90),
            'image/png' => imagepng($normalized, $temporaryPath, 6),
            'image/webp' => imagewebp($normalized, $temporaryPath, 88),
        };
        imagedestroy($normalized);
        if (!$written || !is_file($temporaryPath)) {
            @unlink($temporaryPath);
            throw new PhotoUploadException('A photograph could not be normalized.');
        }
        chmod($temporaryPath, 0640);
        if (!rename($temporaryPath, $finalPath)) {
            @unlink($temporaryPath);
            throw new PhotoUploadException('A photograph could not be committed to private storage.');
        }

        $byteSize = filesize($finalPath);
        if ($byteSize === false || $byteSize < 1) {
            @unlink($finalPath);
            throw new PhotoUploadException('A normalized photograph has an invalid size.');
        }

        return [
            'storage_name' => $storageName,
            'original_name' => $upload['original_name'],
            'mime_type' => $upload['mime_type'],
            'byte_size' => $byteSize,
        ];
    }

    public function absolutePath(string $storageName): ?string
    {
        if (preg_match('#^[1-9][0-9]*/[a-f0-9]{40}\\.(?:jpg|png|webp)$#', $storageName) !== 1) {
            return null;
        }

        [$batchDirectory, $filename] = explode('/', $storageName, 2);
        $root = realpath($this->config->uploadPath);
        if ($root === false || is_link($root)) {
            return null;
        }
        $directory = $root . DIRECTORY_SEPARATOR . $batchDirectory;
        if (is_link($directory)) {
            return null;
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || dirname($resolvedDirectory) !== $root) {
            return null;
        }
        $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $filename;
        return is_file($path) && !is_link($path) ? $path : null;
    }

    public function delete(string $storageName): void
    {
        $path = $this->absolutePath($storageName);
        if ($path !== null) {
            @unlink($path);
            $directory = dirname($path);
            if ($directory !== $this->config->uploadPath && is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    private function decode(string $path, string $mimeType): GdImage
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        if (!$image instanceof GdImage) {
            throw new PhotoUploadException('A photograph could not be decoded safely.');
        }

        return $image;
    }

    private function orient(GdImage $image, string $path, string $mimeType): GdImage
    {
        if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path, 'IFD0', true);
        $orientation = is_array($exif) ? (int) ($exif['IFD0']['Orientation'] ?? 1) : 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }

        $degrees = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);
        if (!$rotated instanceof GdImage) {
            return $image;
        }
        imagedestroy($image);
        return $rotated;
    }

    private function resizeIfNeeded(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $largest = max($width, $height);
        if ($largest <= self::NORMALIZED_DIMENSION) {
            return $image;
        }

        $scale = self::NORMALIZED_DIMENSION / $largest;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        return $resized;
    }

    /**
     * @return array{tmp_path: string, original_name: string, mime_type: string, byte_size: int}
     */
    private function inspectFile(string $path, string $originalName, int $actualSize): array
    {
        if ($actualSize > $this->config->maximumPhotoBytes) {
            throw new PhotoUploadException('Each photograph must be 24 MB or smaller.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mimeType) || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new PhotoUploadException('Photographs must be JPEG, PNG, or WebP files.');
        }

        $dimensions = @getimagesize($path);
        if (!is_array($dimensions)) {
            throw new PhotoUploadException('A selected photograph could not be decoded.');
        }
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width < 1 || $height < 1
            || $width > self::MAXIMUM_DIMENSION
            || $height > self::MAXIMUM_DIMENSION
            || $width * $height > self::MAXIMUM_PIXELS) {
            throw new PhotoUploadException('A photograph has unsupported dimensions.');
        }

        $name = trim(basename(str_replace('\\', '/', $originalName)));
        if ($name === '') {
            $name = 'photo';
        }

        return [
            'tmp_path' => $path,
            'original_name' => mb_substr($name, 0, 180),
            'mime_type' => $mimeType,
            'byte_size' => $actualSize,
        ];
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'A photograph exceeds the upload limit.',
            UPLOAD_ERR_PARTIAL => 'A photograph upload was interrupted.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The server could not receive a photograph.',
            default => 'A photograph could not be uploaded.',
        };
    }
}
