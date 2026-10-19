<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Core\MaatAuth;
use App\Repositories\UserRepository;

final class AuthController
{
    public function loginForm(array $request, array $config): string
    {
        if (Auth::user($config) !== null) {
            header('Location: /');
            exit;
        }

        return View::render('auth/login', ['title' => 'Sign In']);
    }

    public function login(array $request, array $config): string
    {
        if (MaatAuth::enabled()) {
            return $this->maatLogin($request, $config);
        }
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
        if (MaatAuth::enabled() && (Auth::user($config)['auth_source'] ?? '') === 'maat') {
            MaatAuth::configure();
            try {
                \Maat::signOut();
            } catch (\Throwable $error) {
                error_log('DDM Maat sign-out unavailable.');
            }
        }
        $_SESSION = [];
        Auth::logout($config);
        header('Location: /login');
        exit;
    }

    private function maatLogin(array $request, array $config): string
    {
        MaatAuth::configure();
        $action = (string)($request['body']['action'] ?? 'request');
        $email = strtolower(trim((string)($request['body']['email'] ?? '')));
        $showCodeStep = false;
        $message = 'Sign-in is temporarily unavailable. Please try again.';
        try {
            if ($action === 'request' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                unset($_SESSION['maat_sign_in_email']);
                $repo = new UserRepository(Database::pdo($config));
                // Match G2: do not ask Maat to send mail for an unmapped account.
                if (MaatAuth::eligibleUser($repo, $email) === null) {
                    $message = 'If this email is mapped to a DDM account, check your email for the sign-in code.';
                } elseif (\Maat::requestSignIn($email)) {
                    $_SESSION['maat_sign_in_email'] = $email;
                    $showCodeStep = true;
                    $message = 'Check your email for the sign-in code.';
                }
            } elseif ($action === 'verify') {
                $email = (string)($_SESSION['maat_sign_in_email'] ?? '');
                $showCodeStep = $email !== '';
                $code = trim((string)($request['body']['code'] ?? ''));
                $identity = $email !== '' && preg_match('/^[0-9]{6}$/D', $code)
                    ? \Maat::exchangeCode($email, $code, ['session_name' => 'Distributor Deal Manager']) : null;
                $repo = new UserRepository(Database::pdo($config));
                $user = $identity !== null && is_string($identity->email)
                    && is_string($identity->sub) && $identity->sub !== ''
                    && strcasecmp($identity->email, $email) === 0
                    ? MaatAuth::eligibleUser($repo, $identity->email) : null;
                if ($user !== null) {
                    Auth::login($config, $user, 'maat');
                    $repo->updateLastLogin((int)$user['id']);
                    unset($_SESSION['maat_sign_in_email']);
                    header('Location: /');
                    exit;
                }
                $message = 'Unable to sign in. Check your code and that you have an active DDM account.';
            } else {
                $message = 'Enter a valid email address.';
            }
        } catch (\Throwable $error) {
            error_log('DDM Maat sign-in request failed.');
        }
        return View::render('auth/login', [
            'title' => 'Sign In', 'message' => $message,
            'showCodeStep' => $showCodeStep, 'signInEmail' => $email,
        ]);
    }
}
