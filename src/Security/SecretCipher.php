<?php

declare(strict_types=1);

namespace RosinTracker\Security;

use RuntimeException;

final class SecretCipher
{
    private const KEY_VERSION = 1;
    private ?string $key = null;

    public function __construct(private readonly string $keyPath)
    {
    }

    /** @return array{ciphertext: string, nonce: string, keyVersion: int} */
    public function encrypt(string $plaintext, string $purpose): array
    {
        $this->assertAvailable();
        if ($plaintext === '') {
            throw new RuntimeException('An empty secret cannot be encrypted.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $this->associatedData($purpose, self::KEY_VERSION),
            $nonce,
            $this->key(),
        );
        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'keyVersion' => self::KEY_VERSION,
        ];
    }

    public function decrypt(string $ciphertext, string $nonce, string $purpose, int $keyVersion = 1): string
    {
        $this->assertAvailable();
        if ($keyVersion !== self::KEY_VERSION) {
            throw new RuntimeException('This encrypted secret uses an unsupported key version.');
        }
        $decodedCiphertext = base64_decode($ciphertext, true);
        $decodedNonce = base64_decode($nonce, true);
        if (!is_string($decodedCiphertext) || !is_string($decodedNonce)
            || strlen($decodedNonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('The encrypted secret is malformed.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $decodedCiphertext,
            $this->associatedData($purpose, $keyVersion),
            $decodedNonce,
            $this->key(),
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('The encrypted secret could not be opened with this installation key.');
        }
        return $plaintext;
    }

    private function key(): string
    {
        if (is_string($this->key)) {
            return $this->key;
        }

        $directory = dirname($this->keyPath);
        if (is_link($directory)) {
            throw new RuntimeException('The authentication key directory must not be a symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The private authentication key directory could not be created.');
        }

        $lockPath = $this->keyPath . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('The authentication key lock could not be acquired.');
        }

        try {
            if (!is_file($this->keyPath)) {
                $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
                $handle = @fopen($this->keyPath, 'x+b');
                if ($handle === false) {
                    throw new RuntimeException('The private authentication key could not be created.');
                }
                try {
                    if (!chmod($this->keyPath, 0600)
                        || fwrite($handle, $key) !== strlen($key)
                        || !fflush($handle)) {
                        throw new RuntimeException('The private authentication key could not be committed.');
                    }
                } finally {
                    fclose($handle);
                }
            }

            if (is_link($this->keyPath)) {
                throw new RuntimeException('The authentication key must not be a symbolic link.');
            }
            $key = file_get_contents($this->keyPath);
            if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new RuntimeException('The authentication key has an invalid length.');
            }
            if (DIRECTORY_SEPARATOR === '/') {
                $permissions = fileperms($this->keyPath);
                if (!is_int($permissions) || ($permissions & 0077) !== 0) {
                    throw new RuntimeException('The authentication key must be readable only by its owner (0600).');
                }
            }
            $this->key = $key;
            return $key;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @chmod($lockPath, 0600);
        }
    }

    private function associatedData(string $purpose, int $version): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,80}$/', $purpose) !== 1) {
            throw new RuntimeException('The encrypted-secret purpose is invalid.');
        }
        return "rosin-tracker\0{$purpose}\0v{$version}";
    }

    private function assertAvailable(): void
    {
        if (!extension_loaded('sodium')
            || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new RuntimeException('The Sodium PHP extension is required for authentication secrets.');
        }
    }

    public function __destruct()
    {
        if (is_string($this->key) && function_exists('sodium_memzero')) {
            sodium_memzero($this->key);
        }
    }
}
