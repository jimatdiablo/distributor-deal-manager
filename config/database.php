<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => getenv('DB_HOST') ?: getenv('DDM_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: getenv('DDM_DB_PORT') ?: '3306',
    'database' => getenv('DB_NAME') ?: getenv('DDM_DB_NAME') ?: 'distdb',
    'username' => getenv('DB_USER') ?: getenv('DDM_DB_USER') ?: 'ddm',
    'password' => getenv('DB_PASSWORD') ?: getenv('DDM_DB_PASS') ?: '',
    'charset' => 'utf8mb4',
];
