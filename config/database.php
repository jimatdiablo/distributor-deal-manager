<?php

declare(strict_types=1);

$defaultHost = is_file('/.dockerenv') ? 'host.docker.internal' : '127.0.0.1';

return [
    'driver' => 'mysql',
    'host' => getenv('DDM_DB_HOST') ?: $defaultHost,
    'port' => getenv('DDM_DB_PORT') ?: '3306',
    'database' => getenv('DDM_DB_NAME') ?: 'distdb',
    'username' => getenv('DDM_DB_USER') ?: 'root',
    'password' => getenv('DDM_DB_PASS') ?: '',
    'charset' => 'utf8mb4',
];
