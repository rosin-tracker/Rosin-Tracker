<?php

declare(strict_types=1);

use RosinTracker\Database;
use RosinTracker\Repository\AuthenticationRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$arguments = array_slice($argv ?? [], 1);
$disableTotp = $arguments === ['--disable-totp'];
if ($arguments !== [] && !$disableTotp) {
    fwrite(STDERR, "Usage: php bin/auth-force-local.php [--disable-totp]\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $database = (new Database($config))->connection();
    if ($database->query('SELECT 1 FROM owner_accounts WHERE id = 1')->fetchColumn() === false) {
        throw new RuntimeException('No owner account exists yet. Complete first-run setup in the browser.');
    }
    $authentication = new AuthenticationRepository($database);
    $previous = $authentication->activeMethod();
    $authentication->forceLocal();
    if ($disableTotp) {
        $authentication->disableTotp();
    }
    printf(
        "Rosin Tracker sign-in is now local (previous method: %s).\n",
        $previous,
    );
    fwrite(STDOUT, "Use the existing owner name and local password at /login.\n");
    if ($disableTotp) {
        fwrite(STDOUT, "Local two-factor authentication and its recovery codes were disabled.\n");
    }
    fwrite(STDOUT, "The OpenID provider remains configured and can be reactivated from Settings.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Authentication recovery failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
