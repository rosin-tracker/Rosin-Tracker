<?php

declare(strict_types=1);

namespace RosinTracker\Security;

use RosinTracker\Repository\OwnerRepository;

final readonly class Auth
{
    private const SESSION_EPOCH_KEY = '_owner_session_epoch';

    public function __construct(
        private OwnerRepository $owners,
        private Session $session,
        private Csrf $csrf,
    ) {
    }

    public function check(): bool
    {
        $ownerId = $this->session->get('owner_id');
        $sessionEpoch = $this->session->get(self::SESSION_EPOCH_KEY);
        return is_int($ownerId) && $ownerId === 1
            && is_int($sessionEpoch) && $sessionEpoch >= 1
            && $this->owners->findById($ownerId) !== null
            && hash_equals((string) $this->owners->sessionEpoch(), (string) $sessionEpoch);
    }

    /** @return array{id: int, username: string}|null */
    public function owner(): ?array
    {
        $ownerId = $this->session->get('owner_id');
        return is_int($ownerId) ? $this->owners->findById($ownerId) : null;
    }

    public function credentialsValid(string $username, string $password): bool
    {
        $owner = $this->owners->findByUsername($username);
        $hash = $owner['password_hash']
            ?? $this->owners->ownerPasswordHash()
            ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $verified = password_verify($password, $hash);
        return $owner !== null && $verified;
    }

    public function signInOwner(): void
    {
        $epoch = $this->owners->sessionEpoch();
        if ($epoch < 1) {
            throw new \RuntimeException('The owner session could not be established.');
        }
        $this->session->regenerate();
        $this->session->set('owner_id', 1);
        $this->session->set(self::SESSION_EPOCH_KEY, $epoch);
        $this->csrf->rotate();
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
