<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(private PDO $pdo)
    {
    }

    public function run(string $migrationDir): array
    {
        $this->ensureMigrationsTable();

        $files = glob(rtrim($migrationDir, '/\\') . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            $files = [];
        }
        sort($files, SORT_STRING);

        $applied = [];
        $skipped = [];

        foreach ($files as $file) {
            $version = basename($file, '.php');
            if ($this->migrationApplied($version)) {
                $skipped[] = $version;
                continue;
            }

            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException("Migration {$version} must return a callable.");
            }

            $migration($this);
            $this->recordMigration($version);
            $applied[] = $version;
        }

        $this->seedDefaults();

        return [
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute([':table' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name'
        );
        $stmt->execute([
            ':table' => $table,
            ':index_name' => $index,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function constraintExists(string $table, string $constraint): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND table_name = :table AND constraint_name = :constraint_name'
        );
        $stmt->execute([
            ':table' => $table,
            ':constraint_name' => $constraint,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (!$this->columnExists($table, $column)) {
            $this->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        }
    }

    public function renameColumnIfPresent(string $table, string $from, string $to, string $definition): void
    {
        if ($this->columnExists($table, $from) && !$this->columnExists($table, $to)) {
            $this->exec("ALTER TABLE `{$table}` CHANGE COLUMN `{$from}` {$definition}");
        }
    }

    public function addIndexIfMissing(string $table, string $index, string $definition): void
    {
        if (!$this->indexExists($table, $index)) {
            $this->exec("ALTER TABLE `{$table}` ADD {$definition}");
        }
    }

    public function addConstraintIfMissing(string $table, string $constraint, string $definition): void
    {
        if ($this->constraintExists($table, $constraint)) {
            return;
        }

        $this->exec("ALTER TABLE `{$table}` ADD CONSTRAINT {$constraint} {$definition}");
    }

    public function dropConstraintIfPresent(string $table, string $constraint): void
    {
        if ($this->constraintExists($table, $constraint)) {
            $this->exec("ALTER TABLE `{$table}` DROP CHECK {$constraint}");
        }
    }

    public function tryAddConstraintIfMissing(string $table, string $constraint, string $definition): void
    {
        try {
            $this->addConstraintIfMissing($table, $constraint, $definition);
        } catch (Throwable $e) {
            fwrite(STDERR, "Skipping constraint {$constraint}: {$e->getMessage()}\n");
        }
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(128) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function migrationApplied(string $version): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
        $stmt->execute([':version' => $version]);
        return $stmt->fetchColumn() !== false;
    }

    private function recordMigration(string $version): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $stmt->execute([':version' => $version]);
    }

    private function seedDefaults(): void
    {
        if ($this->tableExists('distributors') && $this->columnExists('distributors', 'internal_only')) {
            $this->pdo->exec("UPDATE distributors SET internal_only = 1 WHERE LOWER(TRIM(name)) = 'diablo data'");
        }

        $email = strtolower(trim((string)(getenv('DDM_ADMIN_EMAIL') ?: '')));
        $password = (string)(getenv('DDM_ADMIN_PASSWORD') ?: '');
        $displayName = trim((string)(getenv('DDM_ADMIN_NAME') ?: 'Initial Admin'));
        if ($email === '' || strlen($password) < 8 || !$this->tableExists('users')) {
            return;
        }

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'internal_admin' AND status = 1");
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO users (email, password_hash, display_name, role, distributor_id, status)
             VALUES (:email, :password_hash, :display_name, 'internal_admin', NULL, 1)"
        );
        $insert->execute([
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':display_name' => $displayName !== '' ? $displayName : null,
        ]);
    }
}
