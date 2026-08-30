<?php

declare(strict_types=1);

use RosinTracker\Application;
use RosinTracker\Config;
use RosinTracker\Http\Request;
use RosinTracker\Http\Response;

$temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'rosin-tracker-application-url-' . bin2hex(random_bytes(6));
$originalDataDirectory = getenv('ROSIN_TRACKER_DATA_DIR');
$originalExternalUrl = getenv('ROSIN_TRACKER_EXTERNAL_URL');
putenv('ROSIN_TRACKER_DATA_DIR=' . $temporaryRoot);
putenv('ROSIN_TRACKER_EXTERNAL_URL');

/**
 * @param array<string, mixed> $post
 * @param array<string, mixed> $server
 */
function applicationUrlRequest(
    Application $application,
    string $method,
    string $path,
    array $post = [],
    array $server = [],
): Response {
    return $application->run(new Request($method, $path, [], $post, [], $server));
}

function requireApplicationUrlStatus(Response $response, int $expected, string $step): void
{
    if ($response->status !== $expected) {
        throw new RuntimeException("{$step} returned HTTP {$response->status}; expected {$expected}.");
    }
}

function applicationUrlCsrf(Response $response): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $response->body, $match) !== 1) {
        throw new RuntimeException('A rendered form did not contain a CSRF token.');
    }

    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function requireApplicationUrlBodyContains(Response $response, string $expected, string $step): void
{
    if (!str_contains($response->body, $expected)) {
        throw new RuntimeException("{$step} did not render the expected value: {$expected}");
    }
}

function restoreApplicationUrlEnvironment(string $name, string|false $value): void
{
    if ($value === false) {
        putenv($name);
        return;
    }

    putenv($name . '=' . $value);
}

function removeApplicationUrlTestDirectory(string $directory): void
{
    $resolved = realpath($directory);
    $temporary = realpath(sys_get_temp_dir());
    $expectedPrefix = $temporary === false
        ? ''
        : $temporary . DIRECTORY_SEPARATOR . 'rosin-tracker-application-url-';
    if ($resolved === false || $expectedPrefix === '' || !str_starts_with($resolved, $expectedPrefix)) {
        throw new RuntimeException('Refusing unsafe application-URL test cleanup: ' . $directory);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($resolved);
}

$spoofedProxyServer = [
    'HTTP_HOST' => 'attacker.example',
    'SERVER_NAME' => 'attacker.example',
    'SERVER_PORT' => '80',
    'HTTP_FORWARDED' => 'for=203.0.113.10;host=attacker.example;proto=http',
    'HTTP_X_FORWARDED_HOST' => 'attacker.example',
    'HTTP_X_FORWARDED_PORT' => '80',
    'HTTP_X_FORWARDED_PROTO' => 'http',
];
$ownerPassword = 'application-url-888';
$canonicalUrl = 'https://rosin-tracker.com';
$exitCode = 0;

try {
    $config = require dirname(__DIR__) . '/src/bootstrap.php';
    $application = new Application($config);

    $setupPage = applicationUrlRequest($application, 'GET', '/setup');
    requireApplicationUrlStatus($setupPage, 200, 'Owner setup page');
    $ownerCreated = applicationUrlRequest($application, 'POST', '/setup', [
        '_csrf' => applicationUrlCsrf($setupPage),
        'username' => 'Application URL Owner',
        'password' => $ownerPassword,
        'password_confirmation' => $ownerPassword,
    ]);
    requireApplicationUrlStatus($ownerCreated, 303, 'Owner creation');

    $settingsPage = applicationUrlRequest($application, 'GET', '/settings');
    requireApplicationUrlStatus($settingsPage, 200, 'Settings page');
    $urlUpdated = applicationUrlRequest(
        $application,
        'POST',
        '/settings/application-url',
        [
            '_csrf' => applicationUrlCsrf($settingsPage),
            'application_url' => 'https://Rosin-Tracker.COM/',
            'current_password' => $ownerPassword,
        ],
        $spoofedProxyServer,
    );
    requireApplicationUrlStatus($urlUpdated, 303, 'Application URL update');
    if (($urlUpdated->headers['Location'] ?? '') !== $canonicalUrl . '/login?application-url=updated') {
        throw new RuntimeException('Application URL update did not redirect to the canonical sign-in page.');
    }

    $database = new PDO(
        'sqlite:' . $temporaryRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR
            . 'rosin-tracker.sqlite',
    );
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $storedUrl = $database->query("SELECT value FROM settings WHERE key = 'external_url'")->fetchColumn();
    if ($storedUrl !== $canonicalUrl) {
        throw new RuntimeException('The canonical Application URL was not persisted.');
    }

    $rebuiltApplication = new Application(Config::fromEnvironment(dirname(__DIR__)));
    $rebuiltLogin = applicationUrlRequest($rebuiltApplication, 'GET', '/login');
    requireApplicationUrlStatus($rebuiltLogin, 200, 'Rebuilt sign-in page');
    $signedInAgain = applicationUrlRequest($rebuiltApplication, 'POST', '/login', [
        '_csrf' => applicationUrlCsrf($rebuiltLogin),
        'username' => 'Application URL Owner',
        'password' => $ownerPassword,
    ]);
    requireApplicationUrlStatus($signedInAgain, 303, 'Sign-in after Application URL update');
    $rebuiltSettings = applicationUrlRequest(
        $rebuiltApplication,
        'GET',
        '/settings',
        [],
        $spoofedProxyServer,
    );
    requireApplicationUrlStatus($rebuiltSettings, 200, 'Rebuilt Settings page');
    requireApplicationUrlBodyContains(
        $rebuiltSettings,
        '<code class="application-url-current">' . $canonicalUrl . '</code>',
        'Rebuilt Settings page',
    );
    requireApplicationUrlBodyContains(
        $rebuiltSettings,
        'id="oidc-callback" type="url" value="' . $canonicalUrl . '/auth/oidc/callback" readonly',
        'Rebuilt Settings page',
    );
    if (str_contains($rebuiltSettings->body, 'attacker.example')) {
        throw new RuntimeException('Spoofed host or forwarding headers affected the rendered settings.');
    }

    $csrf = applicationUrlCsrf($rebuiltSettings);
    foreach ([
        'https://rosin-tracker.com/not-an-origin' => 'Malformed Application URL',
        'http://rosin-tracker.com' => 'Non-loopback HTTP Application URL',
    ] as $invalidUrl => $step) {
        $rejected = applicationUrlRequest($rebuiltApplication, 'POST', '/settings/application-url', [
            '_csrf' => $csrf,
            'application_url' => $invalidUrl,
            'current_password' => $ownerPassword,
        ]);
        requireApplicationUrlStatus($rejected, 422, $step);
        $storedAfterRejection = $database->query(
            "SELECT value FROM settings WHERE key = 'external_url'"
        )->fetchColumn();
        if ($storedAfterRejection !== $canonicalUrl) {
            throw new RuntimeException($step . ' changed the stored Application URL.');
        }
    }

    putenv('ROSIN_TRACKER_EXTERNAL_URL=https://managed.example');
    $managedApplication = new Application(Config::fromEnvironment(dirname(__DIR__)));
    $managedSettings = applicationUrlRequest(
        $managedApplication,
        'GET',
        '/settings',
        [],
        $spoofedProxyServer,
    );
    requireApplicationUrlStatus($managedSettings, 200, 'Managed Settings page');
    requireApplicationUrlBodyContains(
        $managedSettings,
        '<code class="application-url-current">https://managed.example</code>',
        'Managed Settings page',
    );
    requireApplicationUrlBodyContains(
        $managedSettings,
        'value="https://managed.example/auth/oidc/callback" readonly',
        'Managed Settings page',
    );
    requireApplicationUrlBodyContains(
        $managedSettings,
        'Managed by the server configuration.',
        'Managed Settings page',
    );
    if (str_contains($managedSettings->body, 'action="/settings/application-url"')
        || str_contains($managedSettings->body, 'id="application-url"')) {
        throw new RuntimeException('A server-managed Application URL rendered an editable form.');
    }
    if ($database->query("SELECT value FROM settings WHERE key = 'external_url'")->fetchColumn() !== $canonicalUrl) {
        throw new RuntimeException('The server-managed override changed the persisted Application URL.');
    }

    fwrite(STDOUT, "Application URL setting integration check passed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Application URL setting integration check failed: ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    restoreApplicationUrlEnvironment('ROSIN_TRACKER_DATA_DIR', $originalDataDirectory);
    restoreApplicationUrlEnvironment('ROSIN_TRACKER_EXTERNAL_URL', $originalExternalUrl);
    if (is_dir($temporaryRoot)) {
        try {
            removeApplicationUrlTestDirectory($temporaryRoot);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'Application URL setting cleanup failed: ' . $cleanupError->getMessage() . PHP_EOL);
            $exitCode = 1;
        }
    }
}

exit($exitCode);
