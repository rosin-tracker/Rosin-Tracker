<?php

declare(strict_types=1);

use RosinTracker\Support\ExternalUrl;

require dirname(__DIR__) . '/src/Support/ExternalUrl.php';

$failures = [];

$validCases = [
    'HTTPS://Rosin-Tracker.COM/' => 'https://rosin-tracker.com',
    'https://Rosin-Tracker.com:443' => 'https://rosin-tracker.com',
    'https://example.com:8443/' => 'https://example.com:8443',
    'https://192.168.1.15/' => 'https://192.168.1.15',
    'https://[2001:DB8::1]:443/' => 'https://[2001:db8::1]',
    'http://LOCALHOST:80/' => 'http://localhost',
    'http://127.0.0.1:5000' => 'http://127.0.0.1:5000',
];

foreach ($validCases as $input => $expected) {
    try {
        $url = ExternalUrl::fromString($input);
        if ($url->value() !== $expected || (string) $url !== $expected) {
            $failures[] = sprintf('%s normalized to %s instead of %s.', $input, $url->value(), $expected);
        }
        if ($url->isHttps() !== str_starts_with($expected, 'https://')) {
            $failures[] = sprintf('%s reported the wrong HTTPS state.', $input);
        }
    } catch (InvalidArgumentException $error) {
        $failures[] = sprintf('%s was rejected: %s', $input, $error->getMessage());
    }
}

$invalidCases = [
    '',
    ' https://example.com',
    "https://example.com\n",
    'https:\\example.com',
    'ftp://example.com',
    'http://example.com',
    'http://[::1]',
    'https://user@example.com',
    'https://user:password@example.com',
    'https://example.com/path',
    'https://example.com/?query=value',
    'https://example.com/#fragment',
    'https://example.com:',
    'https://example.com:0',
    'https://example.com:65536',
    'https://example.com:port',
    'https://-example.com',
    'https://example-.com',
    'https://example..com',
    'https://example.com.',
    'https://example_com',
    'https://999.999.999.999',
    'https://[not-an-address]',
    'https://' . str_repeat('a', 2041),
];

foreach ($invalidCases as $input) {
    try {
        ExternalUrl::fromString($input);
        $failures[] = sprintf('%s was accepted unexpectedly.', var_export($input, true));
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    exit(1);
}

printf("External URL checks passed (%d valid, %d invalid).\n", count($validCases), count($invalidCases));
