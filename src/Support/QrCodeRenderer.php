<?php

declare(strict_types=1);

namespace RosinTracker\Support;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use RuntimeException;

final class QrCodeRenderer
{
    public function renderDataUri(string $value): string
    {
        if ($value === '') {
            throw new RuntimeException('A QR code cannot be generated from an empty value.');
        }
        if (!class_exists(Writer::class)) {
            throw new RuntimeException('Composer dependencies are unavailable. Run composer install first.');
        }

        $png = (new Writer(new GDLibRenderer(240)))->writeString($value);
        return $png !== ''
            ? 'data:image/png;base64,' . base64_encode($png)
            : throw new RuntimeException('The authenticator QR code could not be rendered.');
    }
}
