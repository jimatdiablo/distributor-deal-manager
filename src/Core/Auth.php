<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public const ROLE_INTERNAL_ADMIN = 'internal_admin';
    public const ROLE_INTERNAL_READ_ONLY = 'internal_read_only';
    public const ROLE_AGENT_VIEWER = 'agent_viewer';

    public static function user(array $config): ?array
    {
        $sessionKey = (string)($config['session_key'] ?? 'ddm_user');
        $user = $_SESSION[$sessionKey] ?? null;
        return is_array($user) ? $user : null;
    }

    public static function login(array $config, array $user): void
    {
        $sessionKey = (string)($config['session_key'] ?? 'ddm_user');
        session_regenerate_id(true);
        $_SESSION[$sessionKey] = [
            'id' => (int)($user['id'] ?? 0),
            'email' => (string)($user['email'] ?? ''),
            'display_name' => (string)($user['display_name'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
            'distributor_id' => (int)($user['distributor_id'] ?? 0),
            'is_internal' => self::isInternalRole((string)($user['role'] ?? '')),
        ];
    }

    public static function logout(array $config): void
    {
        $sessionKey = (string)($config['session_key'] ?? 'ddm_user');
        unset($_SESSION[$sessionKey]);
        session_regenerate_id(true);
    }

    public static function csrfToken(): string
    {
        $token = $_SESSION['_csrf_token'] ?? '';
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token'] = $token;
        }

        return $token;
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf_token" value="'
            . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8')
            . '">';
    }

    public static function validateCsrfToken(?string $token): bool
    {
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        return is_string($sessionToken)
            && $sessionToken !== ''
            && is_string($token)
            && hash_equals($sessionToken, $token);
    }

    public static function requireUser(array $config): array
    {
        $user = self::user($config);
        if (is_array($user)) {
            return $user;
        }

        header('Location: /login');
        exit;
    }

    public static function isInternal(array $user): bool
    {
        return self::isInternalRole((string)($user['role'] ?? ''));
    }

    public static function isInternalRole(string $role): bool
    {
        return in_array($role, [self::ROLE_INTERNAL_ADMIN, self::ROLE_INTERNAL_READ_ONLY], true);
    }

    public static function canWrite(array $user): bool
    {
        return (string)($user['role'] ?? '') === self::ROLE_INTERNAL_ADMIN;
    }

    public static function canViewReports(array $user): bool
    {
        return self::isInternal($user);
    }

    public static function canViewTable(array $user, string $table): bool
    {
        $role = (string)($user['role'] ?? '');
        if (in_array($role, [self::ROLE_INTERNAL_ADMIN, self::ROLE_INTERNAL_READ_ONLY], true)) {
            return in_array($table, ['distributors', 'providers', 'deals', 'agent'], true);
        }

        if ($role === self::ROLE_AGENT_VIEWER) {
            return in_array($table, ['distributors', 'providers', 'deals'], true);
        }

        return false;
    }

    public static function scopeDistributorId(array $user): int
    {
        if ((string)($user['role'] ?? '') !== self::ROLE_AGENT_VIEWER) {
            return 0;
        }

        return (int)($user['distributor_id'] ?? 0);
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
        exit;
    }
}
