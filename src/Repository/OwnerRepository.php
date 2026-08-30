<?php

declare(strict_types=1);

namespace RosinTracker\Repository;

use PDO;
use RuntimeException;

final readonly class OwnerRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function exists(): bool
    {
        return $this->database->query('SELECT 1 FROM owner_accounts WHERE id = 1')->fetchColumn() !== false;
    }

    /** @return array{id: int, username: string, password_hash: string}|null */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, username, password_hash FROM owner_accounts WHERE username = :username COLLATE NOCASE'
        );
        $statement->execute(['username' => $username]);
        $owner = $statement->fetch();

        return is_array($owner) ? [
            'id' => (int) $owner['id'],
            'username' => (string) $owner['username'],
            'password_hash' => (string) $owner['password_hash'],
        ] : null;
    }

    /** @return array{id: int, username: string}|null */
    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT id, username FROM owner_accounts WHERE id = :id');
        $statement->execute(['id' => $id]);
        $owner = $statement->fetch();

        return is_array($owner) ? [
            'id' => (int) $owner['id'],
            'username' => (string) $owner['username'],
        ] : null;
    }

    public function ownerPasswordHash(): ?string
    {
        $hash = $this->database->query(
            'SELECT password_hash FROM owner_accounts WHERE id = 1'
        )->fetchColumn();
        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function sessionEpoch(): int
    {
        $epoch = $this->database->query(
            'SELECT session_epoch FROM owner_accounts WHERE id = 1'
        )->fetchColumn();
        return is_int($epoch) || (is_string($epoch) && ctype_digit($epoch)) ? (int) $epoch : 0;
    }

    public function create(string $username, string $password): void
    {
        $hash = $this->hashPassword($password);
        $now = gmdate('Y-m-d\\TH:i:s\\Z');
        $statement = $this->database->prepare(
            'INSERT INTO owner_accounts (id, username, password_hash, created_at, updated_at) '
            . 'VALUES (1, :username, :password_hash, :created_at, :updated_at)'
        );
        $statement->execute([
            'username' => trim($username),
            'password_hash' => $hash,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updatePassword(int $ownerId, string $newPassword): void
    {
        $statement = $this->database->prepare(
            'UPDATE owner_accounts SET password_hash = :password_hash, session_epoch = session_epoch + 1, '
            . 'updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            'password_hash' => $this->hashPassword($newPassword),
            'updated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'id' => $ownerId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The owner password could not be updated.');
        }
    }

    public function revokeSessions(): void
    {
        $statement = $this->database->prepare(
            'UPDATE owner_accounts SET session_epoch = session_epoch + 1, updated_at = :updated_at WHERE id = 1'
        );
        $statement->execute(['updated_at' => gmdate('Y-m-d\\TH:i:s\\Z')]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The owner sessions could not be revoked.');
        }
    }

    private function hashPassword(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash)) {
            throw new RuntimeException('The owner password could not be secured.');
        }
        return $hash;
    }
}
