<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    public static function pdo(array $config): PDO
    {
        $db = require __DIR__ . '/../../config/database.php';

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $db['driver'],
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );

        try {
            return new PDO($dsn, (string)$db['username'], (string)$db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            error_log('DDM database connection failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Database connection failed. Please contact support.';
            exit;
        }
    }
}
