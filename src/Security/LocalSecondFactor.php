<?php

declare(strict_types=1);

namespace RosinTracker\Security;

use RosinTracker\Repository\AuthenticationRepository;
use RosinTracker\Support\QrCodeRenderer;

final class LocalSecondFactor
{
    private const SECRET_PURPOSE = 'owner-totp-secret';
    private const ENROLLMENT_LIFETIME = 900;

    private readonly TotpService $totp;
    private readonly RecoveryCodes $recoveryCodes;
    private readonly QrCodeRenderer $qrCodes;

    public function __construct(
        private readonly AuthenticationRepository $authentication,
        private readonly SecretCipher $cipher,
        ?TotpService $totp = null,
        ?RecoveryCodes $recoveryCodes = null,
        ?QrCodeRenderer $qrCodes = null,
    ) {
        $this->totp = $totp ?? new TotpService();
        $this->recoveryCodes = $recoveryCodes ?? new RecoveryCodes();
        $this->qrCodes = $qrCodes ?? new QrCodeRenderer();
    }

    /**
     * This complete array is safe to serialize into the server-side session. It contains no plaintext
     * TOTP secret except inside the provisioning URI/manual key needed by the setup screen, and expires
     * after fifteen minutes. It must never be logged or flashed.
     *
     * @return array{
     *   ciphertext: string, nonce: string, keyVersion: int, manualKey: string,
     *   provisioningUri: string, qrDataUri: string, issuedAt: int
     * }
     */
    public function beginEnrollment(string $ownerLabel): array
    {
        $enrollment = $this->totp->createEnrollment($ownerLabel);
        $encrypted = $this->cipher->encrypt($enrollment['secret'], self::SECRET_PURPOSE);
        return [
            ...$encrypted,
            'manualKey' => $enrollment['secret'],
            'provisioningUri' => $enrollment['provisioningUri'],
            'qrDataUri' => $this->qrCodes->renderDataUri($enrollment['provisioningUri']),
            'issuedAt' => time(),
        ];
    }

    /**
     * @param array<string, mixed> $pending
     * @return list<string> Plain recovery codes. Show once; do not persist them in the session after display.
     */
    public function confirmEnrollment(array $pending, string $code): array
    {
        $encrypted = $this->pendingEncryptedSecret($pending);
        $issuedAt = isset($pending['issuedAt']) && is_int($pending['issuedAt']) ? $pending['issuedAt'] : 0;
        if ($issuedAt < time() - self::ENROLLMENT_LIFETIME || $issuedAt > time() + 60) {
            throw new AuthenticationException('Authenticator setup expired. Start again.');
        }
        $secret = $this->cipher->decrypt(
            $encrypted['ciphertext'],
            $encrypted['nonce'],
            self::SECRET_PURPOSE,
            $encrypted['keyVersion'],
        );
        try {
            $counter = $this->totp->matchingCounter($secret, $code);
            if ($counter === null) {
                throw new AuthenticationException('That authenticator code is not valid.');
            }
            $recovery = $this->recoveryCodes->generate();
            $this->authentication->enableTotp($encrypted, $counter, $recovery['hashes']);
            return $recovery['codes'];
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            }
        }
    }

    public function verify(string $codeOrRecovery): bool
    {
        $configuration = $this->authentication->totpConfiguration();
        if ($configuration === null) {
            return false;
        }

        $secret = $this->cipher->decrypt(
            $configuration['ciphertext'],
            $configuration['nonce'],
            self::SECRET_PURPOSE,
            $configuration['keyVersion'],
        );
        try {
            $counter = $this->totp->matchingCounter($secret, $codeOrRecovery);
            if ($counter !== null && $this->authentication->advanceTotpCounter($counter)) {
                return true;
            }
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            }
        }

        $matchedId = null;
        foreach ($this->authentication->unusedRecoveryCodes() as $record) {
            if ($this->recoveryCodes->matches($codeOrRecovery, $record['hash'])) {
                $matchedId = $record['id'];
            }
        }
        return is_int($matchedId) && $this->authentication->consumeRecoveryCode($matchedId);
    }

    public function disable(): void
    {
        $this->authentication->disableTotp();
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(string $currentCode): array
    {
        if (!$this->verify($currentCode)) {
            throw new AuthenticationException('Enter a current authenticator or recovery code.');
        }
        $recovery = $this->recoveryCodes->generate();
        $this->authentication->replaceRecoveryCodes($recovery['hashes']);
        return $recovery['codes'];
    }

    /** @param array<string, mixed> $pending @return array{ciphertext: string, nonce: string, keyVersion: int} */
    private function pendingEncryptedSecret(array $pending): array
    {
        $ciphertext = $pending['ciphertext'] ?? null;
        $nonce = $pending['nonce'] ?? null;
        $keyVersion = $pending['keyVersion'] ?? null;
        if (!is_string($ciphertext) || $ciphertext === '' || !is_string($nonce) || $nonce === ''
            || !is_int($keyVersion) || $keyVersion < 1) {
            throw new AuthenticationException('Authenticator setup is missing. Start again.');
        }
        return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'keyVersion' => $keyVersion];
    }
}
