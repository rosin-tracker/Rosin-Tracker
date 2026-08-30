<?php

declare(strict_types=1);

namespace RosinTracker\Security;

final readonly class Csrf
{
    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf_token');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf_token', $token);
        }

        return $token;
    }

    public function valid(string $candidate): bool
    {
        $token = $this->session->get('_csrf_token');
        return is_string($token) && $candidate !== '' && hash_equals($token, $candidate);
    }

    public function rotate(): void
    {
        $this->session->set('_csrf_token', bin2hex(random_bytes(32)));
    }
}
