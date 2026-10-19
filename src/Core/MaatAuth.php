<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\UserRepository;

/** Maat authenticates identity; DDM's enabled local user grants permissions. */
final class MaatAuth
{
    public static function enabled(): bool
    {
        return getenv('MAAT_ENABLED') === '1';
    }

    public static function configure(): void
    {
        require_once __DIR__ . '/MaatClient.php';
        $internal = rtrim(getenv('MAAT_INTERNAL_URL') ?: 'http://host.docker.internal:4000', '/');
        \Maat::configure([
            'internal_url' => $internal,
            'jwks_url' => getenv('MAAT_JWKS_URL') ?: $internal . '/.well-known/jwks.json',
            'service_token' => getenv('MAAT_SERVICE_TOKEN') ?: '',
            'app' => 'distributor-deal-manager',
            'namespace' => 'ddm',
            'cookie_domain' => getenv('MAAT_COOKIE_DOMAIN') ?: null,
            'cookie_domains' => array_filter(array_map('trim', explode(',', getenv('MAAT_COOKIE_DOMAINS') ?: (getenv('MAAT_COOKIE_DOMAIN') ?: '')))),
            'secure_cookies' => getenv('MAAT_SECURE_COOKIES') !== '0',
            'timeout_ms' => (int)(getenv('MAAT_HTTP_TIMEOUT_MS') ?: 10000),
        ]);
    }

    public static function eligibleUser(UserRepository $repo, string $email): ?array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $user = $repo->findActiveByEmail(strtolower(trim($email)));
        if ($user === null || !in_array($user['role'], [Auth::ROLE_INTERNAL_ADMIN, Auth::ROLE_INTERNAL_READ_ONLY, Auth::ROLE_AGENT_VIEWER], true)) {
            return null;
        }
        if ($user['role'] === Auth::ROLE_AGENT_VIEWER) {
            $id = (int)($user['distributor_id'] ?? 0);
            $exists = in_array($id, array_map(static fn(array $row): int => (int)$row['id'], $repo->distributorLookups()), true);
            if ($id <= 0 || !$exists || $repo->distributorIsInternalOnly($id)) {
                return null;
            }
        }
        unset($user['password_hash']);
        $user['is_internal'] = Auth::isInternalRole($user['role']);
        return $user;
    }

    public static function currentUser(array $config): ?array
    {
        self::configure();
        try {
            $identity = \Maat::user();
        } catch (\Throwable $error) {
            error_log('DDM Maat identity verification unavailable.');
            return null;
        }
        if ($identity === null || !is_string($identity->email) || !is_string($identity->sub) || $identity->sub === '') {
            return null;
        }
        return self::eligibleUser(new UserRepository(Database::pdo($config)), $identity->email);
    }
}
