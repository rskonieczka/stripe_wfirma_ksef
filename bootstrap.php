<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Support\Env;

$autoload = __DIR__ . '/vendor/autoload.php';

if (! file_exists($autoload)) {
    throw new RuntimeException('Brak vendor/autoload.php. Uruchom composer install.');
}

require_once $autoload;

Env::load(__DIR__ . '/.env');

return AppConfig::fromEnvironment(__DIR__);
