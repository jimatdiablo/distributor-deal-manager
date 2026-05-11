<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Repositories\UserRepository;

final class AuthController
{
    public function loginForm(array $request, array $config): string
    {
        if (Auth::user($config) !== null) {
            header('Location: /');
            exit;
        }

        return View::render('auth/login', ['title' => 'Login']);
    }

    public function login(array $request, array $config): string
    {
        if (Auth::user($config) !== null) {
            header('Location: /');
            exit;
        }

        $email = strtolower(trim((string)($request['body']['email'] ?? '')));
        $password = (string)($request['body']['password'] ?? '');
        if ($email === '' || $password === '') {
            return View::render('auth/login', [
                'title' => 'Login',
                'message' => 'Enter both email and password.',
            ]);
        }

        $repo = new UserRepository(Database::pdo($config));
        $user = $repo->findActiveByEmail($email);

        $passwordHash = (string)($user['password_hash'] ?? '');
        if (!is_array($user) || $passwordHash === '' || !password_verify($password, $passwordHash)) {
            return View::render('auth/login', [
                'title' => 'Login',
                'message' => 'Invalid credentials.',
            ]);
        }

        $role = (string)($user['role'] ?? '');
        if (!in_array($role, [Auth::ROLE_INTERNAL_ADMIN, Auth::ROLE_INTERNAL_READ_ONLY, Auth::ROLE_AGENT_VIEWER], true)) {
            return View::render('auth/login', [
                'title' => 'Login',
                'message' => 'This account has an invalid role assignment.',
            ]);
        }

        $scopedDistributorId = (int)($user['distributor_id'] ?? 0);
        if ($role === Auth::ROLE_AGENT_VIEWER) {
            if ($scopedDistributorId <= 0) {
                return View::render('auth/login', [
                    'title' => 'Login',
                    'message' => 'Agent viewer accounts must be linked to one distributor.',
                ]);
            }

            if ($repo->distributorIsInternalOnly($scopedDistributorId)) {
                return View::render('auth/login', [
                    'title' => 'Login',
                    'message' => 'Agent viewer accounts cannot be linked to internal-only distributors.',
                ]);
            }
        }

        Auth::login($config, [
            'id' => (int)($user['id'] ?? 0),
            'email' => (string)($user['email'] ?? ''),
            'display_name' => (string)($user['display_name'] ?? ''),
            'role' => $role,
            'distributor_id' => $scopedDistributorId,
        ]);
        $repo->updateLastLogin((int)($user['id'] ?? 0));

        header('Location: /');
        exit;
    }

    public function logout(array $request, array $config): void
    {
        Auth::logout($config);
        header('Location: /login');
        exit;
    }
}
