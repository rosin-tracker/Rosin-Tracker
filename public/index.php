<?php

declare(strict_types=1);

use RosinTracker\Application;
use RosinTracker\Http\Request;
use RosinTracker\Http\Response;

$config = require dirname(__DIR__) . '/src/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'");

try {
    (new Application($config))->run(Request::fromGlobals())->send();
} catch (Throwable $error) {
    error_log((string) $error);
    Response::html(
        '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Rosin Tracker error</title><link rel="stylesheet" href="/assets/app.css">'
        . '<body class="auth-body"><main class="auth-shell"><section class="auth-panel">'
        . '<h1>Rosin Tracker could not start</h1><p>Check the server log for the private error details.</p>'
        . '</section></main></body></html>',
        500,
    )->send();
}
