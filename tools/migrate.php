<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;
use App\Core\MigrationRunner;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);

spl_autoload_register(function (string $class) use ($projectRoot): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $projectRoot . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load($projectRoot);

$attempts = max(1, (int)(getenv('DDM_MIGRATION_DB_ATTEMPTS') ?: 30));
$delaySeconds = max(1, (int)(getenv('DDM_MIGRATION_DB_DELAY_SECONDS') ?: 2));
$config = require $projectRoot . '/config/app.php';
$pdo = null;

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    try {
        $pdo = Database::pdo($config, false);
        break;
    } catch (Throwable $e) {
        if ($attempt >= $attempts) {
            throw $e;
        }

        fwrite(STDERR, sprintf(
            "[%s] waiting for database before migrations (%d/%d): %s\n",
            date(DATE_ATOM),
            $attempt,
            $attempts,
            $e->getMessage()
        ));
        sleep($delaySeconds);
    }
}

if (!$pdo instanceof PDO) {
    throw new RuntimeException('Database unavailable for migrations.');
}

$runner = new MigrationRunner($pdo);
$result = $runner->run($projectRoot . '/migrations');

printf(
    "[%s] migrations complete; applied=%d; skipped=%d\n",
    date(DATE_ATOM),
    count($result['applied']),
    count($result['skipped'])
);

if ($result['applied'] !== []) {
    printf("Applied migrations: %s\n", implode(', ', $result['applied']));
}
