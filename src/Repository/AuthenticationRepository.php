<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final readonly class AuthenticationRepository
{
    public const LOCAL = 'local';
    public const OIDC = 'oidc';
    private const LOGIN_THROTTLE = 'authentication_login_throttle';
    private const SENSITIVE_THROTTLE = 'authentication_sensitive_throttle';

    public function __construct(private PDO $database)
    {
    }

    /**
     * @return array{
     *   activeMethod: string,
     *   local: array{available: bool, totpEnabled: bool, recoveryCodesRemaining: int},
     *   provider: array<string, mixed>|null
     * }
     */
    public function summary(): array
    {
        $activeMethod = $this->activeMethod();
        $totp = $this->totpConfiguration();
        $provider = $this->oidcProvider();
        $identity = $this->oidcIdentity();
        return [
            'activeMethod' => $activeMethod,
            'local' => [
                'available' => true,
                'totpEnabled' => $totp !== null,
                'recoveryCodesRemaining' => $totp === null ? 0 : $this->remainingRecoveryCodes(),
            ],
            'provider' => $provider === null ? null : [
                'type' => $provider['type'],
                'displayName' => $provider['displayName'],
                'tenantId' => $provider['tenantId'],
                'issuer' => $provider['issuer'],
                'clientId' => $provider['clientId'],
                'scopes' => $provider['scopes'],
                'tokenAuthMethod' => $provider['tokenAuthMethod'],
                'configurationFingerprint' => $provider['configurationFingerprint'],
                'verifiedIssuer' => $provider['verifiedIssuer'],
                'verifiedAt' => $provider['verifiedAt'],
                'secretStored' => true,
                'linked' => $identity !== null,
                'active' => $activeMethod === self::OIDC,
                'identity' => $identity,
            ],
        ];
    }

    public function activeMethod(): string
    {
        $value = $this->database->query(
            'SELECT active_method FROM authentication_state WHERE owner_id = 1'
        )->fetchColumn();
        return $value === self::OIDC ? self::OIDC : self::LOCAL;
    }

    public function forceLocal(bool $revokeSessions = true): void
    {
        $this->transaction(function () use ($revokeSessions): void {
            $this->ensureState();
            $statement = $this->database->prepare(
                'UPDATE authentication_state SET active_method = :method, updated_at = :updated_at '
                . 'WHERE owner_id = 1'
            );
            $statement->execute(['method' => self::LOCAL, 'updated_at' => $this->now()]);
            if ($revokeSessions) {
                $this->bumpSessionEpoch();
            }
        });
    }

    public function activateOidc(): void
    {
        if ($this->oidcProvider() === null || $this->oidcIdentity() === null) {
            throw new RuntimeException('OpenID Connect must be validated and linked before activation.');
        }
        $this->transaction(function (): void {
            $this->ensureState();
            $statement = $this->database->prepare(
                'UPDATE authentication_state SET active_method = :method, updated_at = :updated_at '
                . 'WHERE owner_id = 1'
            );
            $statement->execute(['method' => self::OIDC, 'updated_at' => $this->now()]);
            $this->bumpSessionEpoch();
        });
    }

    /**
     * @return array{ciphertext: string, nonce: string, keyVersion: int, lastCounter: int}|null
     */
    public function totpConfiguration(): ?array
    {
        $record = $this->database->query(
            'SELECT secret_ciphertext, secret_nonce, secret_key_version, last_counter '
            . 'FROM owner_totp WHERE owner_id = 1'
        )->fetch();
        return is_array($record) ? [
            'ciphertext' => (string) $record['secret_ciphertext'],
            'nonce' => (string) $record['secret_nonce'],
            'keyVersion' => (int) $record['secret_key_version'],
            'lastCounter' => (int) $record['last_counter'],
        ] : null;
    }

    /**
     * @param array{ciphertext: string, nonce: string, keyVersion: int} $encryptedSecret
     * @param list<string> $recoveryCodeHashes
     */
    public function enableTotp(array $encryptedSecret, int $lastCounter, array $recoveryCodeHashes): void
    {
        if ($lastCounter < 0 || count($recoveryCodeHashes) !== 8) {
            throw new InvalidArgumentException('TOTP requires a verified counter and eight recovery codes.');
        }
        $this->transaction(function () use ($encryptedSecret, $lastCounter, $recoveryCodeHashes): void {
            $this->ensureState();
            if ($this->totpConfiguration() !== null) {
                throw new RuntimeException('An authenticator is already enabled. Disable it before enrolling another.');
            }
            $now = $this->now();
            $statement = $this->database->prepare(
                'INSERT INTO owner_totp ('
                . 'owner_id, secret_ciphertext, secret_nonce, secret_key_version, last_counter, enabled_at, updated_at'
                . ') VALUES (1, :ciphertext, :nonce, :key_version, :last_counter, :enabled_at, :updated_at) '
            );
            try {
                $statement->execute([
                    'ciphertext' => $encryptedSecret['ciphertext'],
                    'nonce' => $encryptedSecret['nonce'],
                    'key_version' => $encryptedSecret['keyVersion'],
                    'last_counter' => $lastCounter,
                    'enabled_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $error) {
                if ((string) $error->getCode() === '23000'
                    || str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
                    throw new RuntimeException(
                        'An authenticator is already enabled. Disable it before enrolling another.',
                        0,
                        $error,
                    );
                }
                throw $error;
            }
            $this->replaceRecoveryCodeHashes($recoveryCodeHashes, $now);
            $this->bumpSessionEpoch();
        });
    }

    public function advanceTotpCounter(int $counter): bool
    {
        if ($counter < 0) {
            return false;
        }
        $statement = $this->database->prepare(
            'UPDATE owner_totp SET last_counter = :counter, updated_at = :updated_at '
            . 'WHERE owner_id = 1 AND last_counter < :counter'
        );
        $statement->execute(['counter' => $counter, 'updated_at' => $this->now()]);
        return $statement->rowCount() === 1;
    }

    public function disableTotp(): void
    {
        $this->transaction(function (): void {
            $this->database->exec('DELETE FROM owner_recovery_codes WHERE owner_id = 1');
            $this->database->exec('DELETE FROM owner_totp WHERE owner_id = 1');
            $this->bumpSessionEpoch();
        });
    }

    /** @return list<array{id: int, hash: string}> */
    public function unusedRecoveryCodes(): array
    {
        $records = $this->database->query(
            'SELECT id, code_hash FROM owner_recovery_codes '
            . 'WHERE owner_id = 1 AND used_at IS NULL ORDER BY id'
        )->fetchAll();
        return array_values(array_map(
            static fn (array $record): array => ['id' => (int) $record['id'], 'hash' => (string) $record['code_hash']],
            $records,
        ));
    }

    public function consumeRecoveryCode(int $id): bool
    {
        $statement = $this->database->prepare(
            'UPDATE owner_recovery_codes SET used_at = :used_at '
            . 'WHERE id = :id AND owner_id = 1 AND used_at IS NULL'
        );
        $statement->execute(['used_at' => $this->now(), 'id' => $id]);
        return $statement->rowCount() === 1;
    }

    public function remainingRecoveryCodes(): int
    {
        return (int) $this->database->query(
            'SELECT COUNT(*) FROM owner_recovery_codes WHERE owner_id = 1 AND used_at IS NULL'
        )->fetchColumn();
    }

    /** @param list<string> $hashes */
    public function replaceRecoveryCodes(array $hashes): void
    {
        if (count($hashes) !== 8) {
            throw new InvalidArgumentException('Exactly eight recovery codes are required.');
        }
        $this->transaction(function () use ($hashes): void {
            $this->replaceRecoveryCodeHashes($hashes, $this->now());
            $this->bumpSessionEpoch();
        });
    }

    /**
     * Internal provider record. Never pass this array directly to a template or log.
     *
     * @return array{
     *   type: string, displayName: string, tenantId: string|null, issuer: string,
     *   clientId: string, clientSecretCiphertext: string, clientSecretNonce: string,
     *   clientSecretKeyVersion: int, scopes: string, tokenAuthMethod: string,
     *   configurationFingerprint: string, verifiedIssuer: string, verifiedAt: string
     * }|null
     */
    public function oidcProvider(): ?array
    {
        $record = $this->database->query(
            'SELECT provider_type, display_name, tenant_id, issuer, client_id, '
            . 'client_secret_ciphertext, client_secret_nonce, client_secret_key_version, scopes, '
            . 'token_auth_method, configuration_fingerprint, verified_issuer, verified_at '
            . 'FROM oidc_provider_config WHERE id = 1'
        )->fetch();
        return is_array($record) ? [
            'type' => (string) $record['provider_type'],
            'displayName' => (string) $record['display_name'],
            'tenantId' => $record['tenant_id'] === null ? null : (string) $record['tenant_id'],
            'issuer' => (string) $record['issuer'],
            'clientId' => (string) $record['client_id'],
            'clientSecretCiphertext' => (string) $record['client_secret_ciphertext'],
            'clientSecretNonce' => (string) $record['client_secret_nonce'],
            'clientSecretKeyVersion' => (int) $record['client_secret_key_version'],
            'scopes' => (string) $record['scopes'],
            'tokenAuthMethod' => (string) $record['token_auth_method'],
            'configurationFingerprint' => (string) $record['configuration_fingerprint'],
            'verifiedIssuer' => (string) $record['verified_issuer'],
            'verifiedAt' => (string) $record['verified_at'],
        ] : null;
    }

    /**
     * @param array{
     *   type: string, displayName: string, tenantId: string|null, issuer: string,
     *   clientId: string, clientSecretCiphertext: string, clientSecretNonce: string,
     *   clientSecretKeyVersion: int, scopes: string, tokenAuthMethod: string,
     *   configurationFingerprint: string, verifiedIssuer: string
     * } $provider
     */
    public function saveOidcProvider(array $provider): void
    {
        $this->transaction(function () use ($provider): void {
            $this->ensureState();
            $current = $this->oidcProvider();
            $identityChanged = $current !== null
                && !hash_equals($current['configurationFingerprint'], $provider['configurationFingerprint']);
            if ($identityChanged) {
                $this->database->exec('DELETE FROM owner_oidc_identity WHERE owner_id = 1');
                $this->forceLocal(false);
            }
            $now = $this->now();
            $statement = $this->database->prepare(
                'INSERT INTO oidc_provider_config ('
                . 'id, owner_id, provider_type, display_name, tenant_id, issuer, client_id, '
                . 'client_secret_ciphertext, client_secret_nonce, client_secret_key_version, scopes, '
                . 'token_auth_method, configuration_fingerprint, verified_issuer, verified_at, created_at, updated_at'
                . ') VALUES ('
                . '1, 1, :type, :display_name, :tenant_id, :issuer, :client_id, :secret_ciphertext, '
                . ':secret_nonce, :secret_key_version, :scopes, :token_auth_method, :fingerprint, '
                . ':verified_issuer, :verified_at, :created_at, :updated_at'
                . ') ON CONFLICT(id) DO UPDATE SET '
                . 'provider_type = excluded.provider_type, display_name = excluded.display_name, '
                . 'tenant_id = excluded.tenant_id, issuer = excluded.issuer, client_id = excluded.client_id, '
                . 'client_secret_ciphertext = excluded.client_secret_ciphertext, '
                . 'client_secret_nonce = excluded.client_secret_nonce, '
                . 'client_secret_key_version = excluded.client_secret_key_version, scopes = excluded.scopes, '
                . 'token_auth_method = excluded.token_auth_method, '
                . 'configuration_fingerprint = excluded.configuration_fingerprint, '
                . 'verified_issuer = excluded.verified_issuer, verified_at = excluded.verified_at, '
                . 'updated_at = excluded.updated_at'
            );
            $statement->execute([
                'type' => $provider['type'],
                'display_name' => $provider['displayName'],
                'tenant_id' => $provider['tenantId'],
                'issuer' => $provider['issuer'],
                'client_id' => $provider['clientId'],
                'secret_ciphertext' => $provider['clientSecretCiphertext'],
                'secret_nonce' => $provider['clientSecretNonce'],
                'secret_key_version' => $provider['clientSecretKeyVersion'],
                'scopes' => $provider['scopes'],
                'token_auth_method' => $provider['tokenAuthMethod'],
                'fingerprint' => $provider['configurationFingerprint'],
                'verified_issuer' => $provider['verifiedIssuer'],
                'verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * @param array{
     *   issuer: string, subject: string, displayName?: string|null, email?: string|null,
     *   entraTenantId?: string|null, entraObjectId?: string|null
     * } $identity
     */
    public function linkOidcIdentity(array $identity): void
    {
        $provider = $this->oidcProvider();
        if ($provider === null || !hash_equals($provider['verifiedIssuer'], $identity['issuer'])) {
            throw new RuntimeException('The returned OpenID identity does not match the validated provider.');
        }
        if ($this->activeMethod() !== self::LOCAL || $this->oidcIdentity() !== null) {
            throw new RuntimeException(
                'An OpenID identity can be linked only once while local sign-in is active.',
            );
        }
        if (trim($identity['subject']) === '') {
            throw new InvalidArgumentException('The OpenID subject is missing.');
        }
        $statement = $this->database->prepare(
            'INSERT INTO owner_oidc_identity ('
            . 'owner_id, provider_id, issuer, subject, display_name, email, entra_tenant_id, entra_object_id, linked_at'
            . ') VALUES (1, 1, :issuer, :subject, :display_name, :email, :tenant_id, :object_id, :linked_at)'
        );
        try {
            $statement->execute([
                'issuer' => $identity['issuer'],
                'subject' => $identity['subject'],
                'display_name' => $identity['displayName'] ?? null,
                'email' => $identity['email'] ?? null,
                'tenant_id' => $identity['entraTenantId'] ?? null,
                'object_id' => $identity['entraObjectId'] ?? null,
                'linked_at' => $this->now(),
            ]);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000'
                || str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
                throw new RuntimeException('An OpenID identity is already linked.', 0, $error);
            }
            throw $error;
        }
    }

    /** @return array<string, string|null>|null */
    public function oidcIdentity(): ?array
    {
        $record = $this->database->query(
            'SELECT issuer, subject, display_name, email, entra_tenant_id, entra_object_id, linked_at '
            . 'FROM owner_oidc_identity WHERE owner_id = 1'
        )->fetch();
        return is_array($record) ? [
            'issuer' => (string) $record['issuer'],
            'subject' => (string) $record['subject'],
            'displayName' => $record['display_name'] === null ? null : (string) $record['display_name'],
            'email' => $record['email'] === null ? null : (string) $record['email'],
            'entraTenantId' => $record['entra_tenant_id'] === null ? null : (string) $record['entra_tenant_id'],
            'entraObjectId' => $record['entra_object_id'] === null ? null : (string) $record['entra_object_id'],
            'linkedAt' => (string) $record['linked_at'],
        ] : null;
    }

    public function oidcIdentityMatches(string $issuer, string $subject): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM owner_oidc_identity WHERE owner_id = 1 AND issuer = :issuer AND subject = :subject'
        );
        $statement->execute(['issuer' => $issuer, 'subject' => $subject]);
        return $statement->fetchColumn() !== false;
    }

    public function removeOidc(): void
    {
        $this->transaction(function (): void {
            $this->forceLocal();
            $this->database->exec('DELETE FROM oidc_provider_config WHERE id = 1');
        });
    }

    public function recordLoginFailure(): void
    {
        $this->recordThrottleFailure(self::LOGIN_THROTTLE);
    }

    public function clearLoginFailures(): void
    {
        $this->clearThrottle(self::LOGIN_THROTTLE);
    }

    public function loginBlockedFor(): int
    {
        return $this->throttleBlockedFor(self::LOGIN_THROTTLE);
    }

    public function recordSensitiveFailure(): void
    {
        $this->recordThrottleFailure(self::SENSITIVE_THROTTLE);
    }

    public function clearSensitiveFailures(): void
    {
        $this->clearThrottle(self::SENSITIVE_THROTTLE);
    }

    public function sensitiveOperationBlockedFor(): int
    {
        return $this->throttleBlockedFor(self::SENSITIVE_THROTTLE);
    }

    private function ensureState(): void
    {
        $statement = $this->database->prepare(
            'INSERT OR IGNORE INTO authentication_state (owner_id, active_method, updated_at) '
            . 'VALUES (1, :method, :updated_at)'
        );
        $statement->execute(['method' => self::LOCAL, 'updated_at' => $this->now()]);
    }

    private function bumpSessionEpoch(): void
    {
        $statement = $this->database->prepare(
            'UPDATE owner_accounts SET session_epoch = session_epoch + 1, updated_at = :updated_at WHERE id = 1'
        );
        $statement->execute(['updated_at' => $this->now()]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The owner session generation could not be updated.');
        }
    }

    private function recordThrottleFailure(string $table): void
    {
        $now = time();
        $statement = $this->database->prepare(
            "INSERT INTO {$table} "
            . '(owner_id, failure_count, blocked_until, updated_at) VALUES (1, 1, NULL, :now) '
            . 'ON CONFLICT(owner_id) DO UPDATE SET '
            . 'failure_count = CASE '
            . "WHEN {$table}.blocked_until > :now THEN {$table}.failure_count "
            . "WHEN {$table}.failure_count >= 4 THEN 0 "
            . "ELSE {$table}.failure_count + 1 END, "
            . 'blocked_until = CASE '
            . "WHEN {$table}.blocked_until > :now THEN {$table}.blocked_until "
            . "WHEN {$table}.failure_count >= 4 THEN :blocked_until "
            . 'ELSE NULL END, updated_at = :now'
        );
        $statement->execute(['now' => $now, 'blocked_until' => $now + 30]);
    }

    private function clearThrottle(string $table): void
    {
        $this->database->exec("DELETE FROM {$table} WHERE owner_id = 1");
    }

    private function throttleBlockedFor(string $table): int
    {
        $blockedUntil = $this->database->query(
            "SELECT blocked_until FROM {$table} WHERE owner_id = 1"
        )->fetchColumn();
        if (!is_int($blockedUntil) && !(is_string($blockedUntil) && ctype_digit($blockedUntil))) {
            return 0;
        }
        $remaining = (int) $blockedUntil - time();
        if ($remaining <= 0) {
            $this->clearThrottle($table);
            return 0;
        }
        return $remaining;
    }

    /** @param list<string> $hashes */
    private function replaceRecoveryCodeHashes(array $hashes, string $now): void
    {
        $this->database->exec('DELETE FROM owner_recovery_codes WHERE owner_id = 1');
        $statement = $this->database->prepare(
            'INSERT INTO owner_recovery_codes (owner_id, code_hash, created_at, used_at) '
            . 'VALUES (1, :code_hash, :created_at, NULL)'
        );
        foreach ($hashes as $hash) {
            $statement->execute(['code_hash' => $hash, 'created_at' => $now]);
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z');
    }

    private function transaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->database->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }
}
