<?php

declare(strict_types=1);

namespace RosinTracker\Security;

use OTPHP\TOTP;
use RosinTracker\Support\SystemClock;
use RuntimeException;

final class TotpService
{
    public const DIGEST = 'sha1';
    public const DIGITS = 6;
    public const PERIOD = 30;
    private const ISSUER = 'Rosin Tracker';

    /** @return array{secret: string, provisioningUri: string} */
    public function createEnrollment(string $ownerLabel): array
    {
        $this->assertAvailable();
        $label = trim($ownerLabel) !== '' ? trim($ownerLabel) : 'Owner';
        $totp = TOTP::generate(new SystemClock(), 20)
            ->withPeriod(self::PERIOD)
            ->withDigest(self::DIGEST)
            ->withDigits(self::DIGITS)
            ->withIssuer(self::ISSUER)
            ->withLabel($label);
        return ['secret' => $totp->getSecret(), 'provisioningUri' => $totp->getProvisioningUri()];
    }

    public function matchingCounter(string $secret, string $candidate, ?int $timestamp = null): ?int
    {
        $this->assertAvailable();
        $code = (string) preg_replace('/\D/', '', $candidate);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $now = $timestamp ?? time();
        if ($now < 0) {
            return null;
        }
        $totp = TOTP::create(
            secret: $secret,
            period: self::PERIOD,
            digest: self::DIGEST,
            digits: self::DIGITS,
            clock: new SystemClock(),
        );
        $currentCounter = intdiv($now, self::PERIOD);
        foreach ([0, -1, 1] as $offset) {
            $counter = $currentCounter + $offset;
            if ($counter < 0) {
                continue;
            }
            if (hash_equals($totp->at($counter * self::PERIOD), $code)) {
                return $counter;
            }
        }
        return null;
    }

    private function assertAvailable(): void
    {
        if (!class_exists(TOTP::class)) {
            throw new RuntimeException('Composer dependencies are unavailable. Run composer install first.');
        }
    }
}
