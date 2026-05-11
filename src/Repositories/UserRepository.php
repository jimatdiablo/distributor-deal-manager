<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function usersTableExists(): bool
    {
        return $this->tableExists('users');
    }

    public function findActiveByEmail(string $email): ?array
    {
        if (!$this->tableExists('users')) {
            return null;
        }

        $sql = 'SELECT id, email, password_hash, role, distributor_id, status, display_name
                FROM users
                WHERE email = :email AND status = 1
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':email', strtolower(trim($email)));
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function allUsers(?string $search = null): array
    {
        if (!$this->tableExists('users')) {
            return [];
        }

        $search = strtolower(trim((string)$search));
        if ($search === '') {
            $stmt = $this->pdo->query('SELECT id, email, display_name, role, distributor_id, status, last_login_at, created_at, updated_at FROM users ORDER BY email ASC');
            return $stmt->fetchAll();
        }

        $sql = 'SELECT id, email, display_name, role, distributor_id, status, last_login_at, created_at, updated_at
                FROM users
                WHERE LOWER(email) LIKE :q
                   OR LOWER(COALESCE(display_name, "")) LIKE :q
                   OR LOWER(role) LIKE :q
                ORDER BY email ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':q', '%' . $search . '%');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findUserById(int $id): ?array
    {
        if ($id <= 0 || !$this->tableExists('users')) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, email, display_name, role, distributor_id, status, last_login_at, created_at, updated_at FROM users WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function createUser(array $data): void
    {
        if (!$this->tableExists('users')) {
            return;
        }

        $sql = 'INSERT INTO users (email, password_hash, display_name, role, distributor_id, status)
                VALUES (:email, :password_hash, :display_name, :role, :distributor_id, :status)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':email', strtolower(trim((string)($data['email'] ?? ''))));
        $stmt->bindValue(':password_hash', (string)($data['password_hash'] ?? ''));
        $stmt->bindValue(':display_name', trim((string)($data['display_name'] ?? '')) !== '' ? trim((string)$data['display_name']) : null);
        $stmt->bindValue(':role', (string)($data['role'] ?? ''));

        $distributorId = (int)($data['distributor_id'] ?? 0);
        if ($distributorId > 0) {
            $stmt->bindValue(':distributor_id', $distributorId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':distributor_id', null, PDO::PARAM_NULL);
        }

        $stmt->bindValue(':status', ((int)($data['status'] ?? 1)) === 1 ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function updateUser(int $id, array $data): void
    {
        if ($id <= 0 || !$this->tableExists('users')) {
            return;
        }

        $sql = 'UPDATE users
                SET email = :email,
                    display_name = :display_name,
                    role = :role,
                    distributor_id = :distributor_id,
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':email', strtolower(trim((string)($data['email'] ?? ''))));
        $stmt->bindValue(':display_name', trim((string)($data['display_name'] ?? '')) !== '' ? trim((string)$data['display_name']) : null);
        $stmt->bindValue(':role', (string)($data['role'] ?? ''));

        $distributorId = (int)($data['distributor_id'] ?? 0);
        if ($distributorId > 0) {
            $stmt->bindValue(':distributor_id', $distributorId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':distributor_id', null, PDO::PARAM_NULL);
        }

        $stmt->bindValue(':status', ((int)($data['status'] ?? 1)) === 1 ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        if ($id <= 0 || $passwordHash === '' || !$this->tableExists('users')) {
            return;
        }

        $sql = 'UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':password_hash', $passwordHash);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function distributorLookups(): array
    {
        if (!$this->tableExists('distributors')) {
            return [];
        }

        $hasInternalOnly = $this->columnExists('distributors', 'internal_only');
        $sql = $hasInternalOnly
            ? 'SELECT id, name, internal_only FROM distributors ORDER BY name ASC'
            : 'SELECT id, name, 0 AS internal_only FROM distributors ORDER BY name ASC';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function updateLastLogin(int $userId): void
    {
        if ($userId <= 0 || !$this->tableExists('users')) {
            return;
        }

        $sql = 'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function distributorIsInternalOnly(int $distributorId): bool
    {
        if ($distributorId <= 0 || !$this->tableExists('distributors')) {
            return false;
        }

        $hasInternalOnly = $this->columnExists('distributors', 'internal_only');
        if ($hasInternalOnly) {
            $sql = 'SELECT internal_only FROM distributors WHERE id = :id LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $distributorId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            return ((int)($row['internal_only'] ?? 0)) === 1;
        }

        $sql = 'SELECT name FROM distributors WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $distributorId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return strtolower(trim((string)($row['name'] ?? ''))) === 'diablo data';
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->bindValue(':table_name', $table);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :column_name');
        $stmt->bindValue(':column_name', $column);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }
}
