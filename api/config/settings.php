<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

return [
    'db' => [
        'host' => $_ENV['MYSQL_HOST'] ?? 'mysql',
        'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
        'database' => $_ENV['MYSQL_DATABASE'] ?? 't3withme',
        'username' => $_ENV['MYSQL_USER'] ?? 't3withme',
        'password' => $_ENV['MYSQL_PASSWORD'] ?? 't3withme',
    ],
    'cors_origin' => $_ENV['CORS_ORIGIN'] ?? '*',
    'geoip_db' => __DIR__ . '/../data/GeoLite2-City.mmdb',
    'rate_limit' => [
        'max_requests' => 10,
        'window_seconds' => 60,
    ],
];
