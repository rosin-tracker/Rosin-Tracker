<?php

declare(strict_types=1);

use RosinTracker\Config;

$composerAutoloader = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($composerAutoloader)) {
    throw new RuntimeException(
        'Composer dependencies are missing. Run composer install --no-dev --classmap-authoritative.'
    );
}
require_once $composerAutoloader;

$config = Config::fromEnvironment(dirname(__DIR__));
date_default_timezone_set($config->timezone);

return $config;
