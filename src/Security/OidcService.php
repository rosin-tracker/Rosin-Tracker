<?php

declare(strict_types=1);

namespace RosinTracker\Security;

use DigitalCz\OpenIDConnect\OidcFactory;
use RosinTracker\Repository\AuthenticationRepository;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;

final class OidcService
{
    public const CALLBACK_PATH = '/auth/oidc/callback';
    private const SECRET_PURPOSE = 'oidc-client-secret';
    private const FLOW_LIFETIME = 600;

    public function __construct(
        private readonly AuthenticationRepository $authentication,
        private readonly SecretCipher $cipher,
        private readonly string $externalUrl,
    ) {
    }

    public function callbackUrl(): string
    {
        return $this->externalUrl . self::CALLBACK_PATH;
    }

    public function composerReady(): bool
    {
        return class_exists(OidcFactory::class) && class_exists(HttpClient::class);
    }

    public function externalUrlIsHttps(): bool
    {
        return strtolower((string) parse_url($this->externalUrl, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * Expected form keys: provider_type, display_name, tenant_id, issuer, client_id,
     * client_secret, scopes, token_auth_method. The secret must be passed untrimmed.
     * A blank secret preserves it only when the provider identity is unchanged.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> Public provider summary; contains no secret material.
     */
    public function saveAndValidate(array $input): array
    {
        $candidate = $this->normalizeProvider($input);
        $existing = $this->authentication->oidcProvider();
        $secretInput = $input['client_secret'] ?? '';
        $newSecret = is_scalar($secretInput) ? (string) $secretInput : '';
        if ($newSecret === '') {
            if ($existing === null
                || !hash_equals($existing['configurationFingerprint'], $candidate['configurationFingerprint'])) {
                throw new AuthenticationException('Enter a client secret for this provider configuration.');
            }
            $secret = $this->cipher->decrypt(
                $existing['clientSecretCiphertext'],
                $existing['clientSecretNonce'],
                self::SECRET_PURPOSE,
                $existing['clientSecretKeyVersion'],
            );
            $encrypted = [
                'ciphertext' => $existing['clientSecretCiphertext'],
                'nonce' => $existing['clientSecretNonce'],
                'keyVersion' => $existing['clientSecretKeyVersion'],
            ];
        } else {
            if (strlen($newSecret) > 4096) {
                throw new AuthenticationException('The client secret is too long.');
            }
            $secret = $newSecret;
            $encrypted = $this->cipher->encrypt($secret, self::SECRET_PURPOSE);
        }

        try {
            $this->createClient($candidate, $secret)->authorizationCode()->createAuthorizationUrl();
            $this->authentication->saveOidcProvider([
                ...$candidate,
                'clientSecretCiphertext' => $encrypted['ciphertext'],
                'clientSecretNonce' => $encrypted['nonce'],
                'clientSecretKeyVersion' => $encrypted['keyVersion'],
                'verifiedIssuer' => $candidate['issuer'],
            ]);
        } catch (AuthenticationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthenticationException(
                'The OpenID provider metadata could not be validated. Check the issuer and try again.',
                0,
                $error,
            );
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            }
        }

        $provider = $this->authentication->summary()['provider'];
        return is_array($provider) ? $provider : throw new RuntimeException('The provider was not saved.');
    }

    /**
     * Persist the returned array under one server-side session key (for example `oidc_flow`).
     * It contains no client secret or tokens and must be removed before processing the callback.
     *
     * @return array{
     *   url: string, state: string, nonce: string, codeVerifier: string,
     *   purpose: string, issuedAt: int, providerFingerprint: string, redirectFingerprint: string
     * }
     */
    public function begin(string $purpose): array
    {
        if (!in_array($purpose, ['link', 'login'], true)) {
            throw new AuthenticationException('The OpenID sign-in purpose is invalid.');
        }
        if ($purpose === 'login' && $this->authentication->activeMethod() !== AuthenticationRepository::OIDC) {
            throw new AuthenticationException('OpenID Connect is not the active sign-in method.');
        }
        $provider = $this->requireProvider();
        $secret = $this->decryptProviderSecret($provider);
        try {
            $result = $this->createClient($provider, $secret)->authorizationCode()->createAuthorizationUrl();
            $nonce = $result->nonce();
            $codeVerifier = $result->codeVerifier();
            if (!is_string($nonce) || $nonce === '' || !is_string($codeVerifier) || $codeVerifier === '') {
                throw new AuthenticationException('The provider did not start a secure OpenID flow.');
            }
            return [
                'url' => $result->url(),
                'state' => $result->state(),
                'nonce' => $nonce,
                'codeVerifier' => $codeVerifier,
                'purpose' => $purpose,
                'issuedAt' => time(),
                'providerFingerprint' => $provider['configurationFingerprint'],
                'redirectFingerprint' => hash('sha256', $this->callbackUrl()),
            ];
        } catch (AuthenticationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthenticationException('OpenID sign-in could not be started.', 0, $error);
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            }
        }
    }

    /**
     * @param array<string, mixed> $flow
     * @return array{purpose: string, issuer: string, subject: string}
     */
    public function complete(string $code, string $returnedState, array $flow): array
    {
        $provider = $this->requireProvider();
        $purpose = $flow['purpose'] ?? null;
        $state = $flow['state'] ?? null;
        $nonce = $flow['nonce'] ?? null;
        $codeVerifier = $flow['codeVerifier'] ?? null;
        $issuedAt = $flow['issuedAt'] ?? null;
        $fingerprint = $flow['providerFingerprint'] ?? null;
        $redirectFingerprint = $flow['redirectFingerprint'] ?? null;
        if (!is_string($purpose) || !in_array($purpose, ['link', 'login'], true)
            || !is_string($state) || $state === '' || !is_string($returnedState) || $returnedState === ''
            || !hash_equals($state, $returnedState)
            || !is_string($nonce) || $nonce === ''
            || !is_string($codeVerifier) || $codeVerifier === ''
            || !is_int($issuedAt) || $issuedAt < time() - self::FLOW_LIFETIME || $issuedAt > time() + 60
            || !is_string($fingerprint)
            || !hash_equals($provider['configurationFingerprint'], $fingerprint)
            || !is_string($redirectFingerprint)
            || !hash_equals(hash('sha256', $this->callbackUrl()), $redirectFingerprint)) {
            throw new AuthenticationException('The OpenID sign-in request expired or did not match this session.');
        }
        if ($code === '') {
            throw new AuthenticationException('The OpenID provider did not return an authorization code.');
        }
        if ($purpose === 'login'
            && $this->authentication->activeMethod() !== AuthenticationRepository::OIDC) {
            throw new AuthenticationException('OpenID Connect is no longer the active sign-in method.');
        }

        $secret = $this->decryptProviderSecret($provider);
        try {
            $tokens = $this->createClient($provider, $secret)->authorizationCode()->fetchTokens(
                code: $code,
                nonce: $nonce,
                codeVerifier: $codeVerifier,
            );
            $idToken = $tokens->idToken();
            if ($idToken === null || trim($idToken->sub()) === '') {
                throw new AuthenticationException('The OpenID provider returned no stable subject identifier.');
            }
            $issuer = $provider['verifiedIssuer'];
            if (method_exists($idToken, 'iss')) {
                $tokenIssuer = $idToken->iss();
                if (is_string($tokenIssuer) && $tokenIssuer !== '') {
                    $issuer = $tokenIssuer;
                }
            }
            if (!hash_equals($provider['verifiedIssuer'], $issuer)) {
                throw new AuthenticationException('The OpenID token issuer did not match the configured provider.');
            }
            return ['purpose' => $purpose, 'issuer' => $issuer, 'subject' => $idToken->sub()];
        } catch (AuthenticationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthenticationException('OpenID sign-in could not be completed.', 0, $error);
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            }
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function normalizeProvider(array $input): array
    {
        $type = $this->field($input, 'provider_type');
        if (!in_array($type, ['entra', 'generic'], true)) {
            throw new AuthenticationException('Choose Microsoft Entra ID or another OpenID provider.');
        }
        $displayName = $this->field($input, 'display_name');
        if ($displayName === '') {
            $displayName = $type === 'entra' ? 'Microsoft Entra ID' : 'OpenID Connect';
        }
        if (mb_strlen($displayName) > 80) {
            throw new AuthenticationException('The provider name must be 80 characters or fewer.');
        }
        $tenantId = null;
        if ($type === 'entra') {
            $tenantId = strtolower($this->field($input, 'tenant_id'));
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $tenantId) !== 1) {
                throw new AuthenticationException('Enter the Microsoft Entra Directory (tenant) ID as a GUID.');
            }
            $issuer = 'https://login.microsoftonline.com/' . $tenantId . '/v2.0';
        } else {
            $issuer = $this->field($input, 'issuer');
            $this->assertIssuerUrl($issuer);
        }
        $clientId = $this->field($input, 'client_id');
        if ($clientId === '' || strlen($clientId) > 512) {
            throw new AuthenticationException('Enter a valid client ID.');
        }
        if ($type === 'entra'
            && preg_match('/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/', $clientId) !== 1) {
            throw new AuthenticationException('Enter the Microsoft Entra Application (client) ID as a GUID.');
        }
        $scopeItems = preg_split('/\s+/', $this->field($input, 'scopes') ?: 'openid profile email') ?: [];
        $scopeItems = array_values(array_unique(array_filter($scopeItems, static fn (string $scope): bool => $scope !== '')));
        foreach ($scopeItems as $scope) {
            if (preg_match('/^[A-Za-z0-9._:\/-]{1,120}$/', $scope) !== 1) {
                throw new AuthenticationException('The requested OpenID scopes are invalid.');
            }
        }
        if (!in_array('openid', $scopeItems, true) || count($scopeItems) > 20) {
            throw new AuthenticationException('OpenID scopes must include openid and no more than 20 values.');
        }
        $scopes = implode(' ', $scopeItems);
        $tokenAuthMethod = $type === 'entra' ? 'client_secret_post' : $this->field($input, 'token_auth_method');
        if (!in_array($tokenAuthMethod, ['client_secret_basic', 'client_secret_post'], true)) {
            $tokenAuthMethod = 'client_secret_post';
        }
        $fingerprint = hash('sha256', $type . "\0" . $issuer . "\0" . $clientId);
        return [
            'type' => $type,
            'displayName' => $displayName,
            'tenantId' => $tenantId,
            'issuer' => $issuer,
            'clientId' => $clientId,
            'scopes' => $scopes,
            'tokenAuthMethod' => $tokenAuthMethod,
            'configurationFingerprint' => $fingerprint,
        ];
    }

    /** @param array<string, mixed> $provider */
    private function createClient(array $provider, string $secret): object
    {
        if (!$this->composerReady()) {
            throw new AuthenticationException('Composer dependencies are unavailable. Run composer install first.');
        }
        $this->assertCallbackUrl();
        $httpClient = HttpClient::create([
            'timeout' => 8.0,
            'max_duration' => 15.0,
            'max_redirects' => 2,
            'verify_peer' => true,
            'verify_host' => true,
            'headers' => ['User-Agent' => 'Rosin-Tracker-OIDC/1.0'],
        ]);
        return OidcFactory::create(
            httpClient: $httpClient,
            issuer: $provider['issuer'],
            clientId: $provider['clientId'],
            clientSecret: $secret,
            redirectUri: $this->callbackUrl(),
            defaultScopes: explode(' ', $provider['scopes']),
            authenticationMethod: $provider['tokenAuthMethod'],
            pkceMethod: 'S256',
            cacheSecret: $provider['configurationFingerprint'],
        );
    }

    /** @return array<string, mixed> */
    private function requireProvider(): array
    {
        $provider = $this->authentication->oidcProvider();
        if ($provider === null) {
            throw new AuthenticationException('Configure and validate an OpenID provider first.');
        }
        return $provider;
    }

    /** @param array<string, mixed> $provider */
    private function decryptProviderSecret(array $provider): string
    {
        return $this->cipher->decrypt(
            $provider['clientSecretCiphertext'],
            $provider['clientSecretNonce'],
            self::SECRET_PURPOSE,
            $provider['clientSecretKeyVersion'],
        );
    }

    private function assertCallbackUrl(): void
    {
        $this->assertIssuerUrl($this->callbackUrl(), 'The external URL must use HTTPS before OpenID Connect can be enabled.');
    }

    private function assertIssuerUrl(string $url, string $message = 'Enter a valid HTTPS issuer URL.'): void
    {
        if (strlen($url) > 2048) {
            throw new AuthenticationException($message);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new AuthenticationException($message);
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (strtolower((string) $parts['scheme']) !== 'https'
            && !(strtolower((string) $parts['scheme']) === 'http' && $isLocalhost)) {
            throw new AuthenticationException($message);
        }
    }

    /** @param array<string, mixed> $input */
    private function field(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
