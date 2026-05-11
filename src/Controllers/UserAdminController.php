<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Repositories\UserRepository;

final class UserAdminController
{
    public function index(array $request, array $config): string
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canWrite($authUser)) {
            Auth::forbidden();
        }

        $repo = new UserRepository(Database::pdo($config));
        $search = trim((string)($request['query']['q'] ?? ''));
        $editId = isset($request['query']['edit']) ? (int)$request['query']['edit'] : 0;
        $message = trim((string)($request['query']['message'] ?? ''));

        $tableReady = $repo->usersTableExists();
        $users = $tableReady ? $repo->allUsers($search) : [];
        $editUser = ($tableReady && $editId > 0) ? $repo->findUserById($editId) : null;
        $distributors = $repo->distributorLookups();

        return View::render('users/index', [
            'title' => 'Users',
            'currentUser' => $authUser,
            'searchQuery' => $search,
            'users' => $users,
            'editUser' => $editUser,
            'distributors' => $distributors,
            'tableReady' => $tableReady,
            'message' => $message,
        ]);
    }

    public function create(array $request, array $config): void
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canWrite($authUser)) {
            Auth::forbidden();
        }

        $repo = new UserRepository(Database::pdo($config));
        if (!$repo->usersTableExists()) {
            header('Location: /users?message=' . urlencode('Users table not found. Apply RBAC migration first.'));
            exit;
        }

        $payload = $this->sanitizePayload($request['body'] ?? []);
        $validation = $this->validatePayload($repo, $payload, true);
        if ($validation !== null) {
            header('Location: /users?message=' . urlencode($validation));
            exit;
        }

        try {
            $repo->createUser([
                'email' => $payload['email'],
                'password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
                'display_name' => $payload['display_name'],
                'role' => $payload['role'],
                'distributor_id' => $payload['role'] === Auth::ROLE_AGENT_VIEWER ? $payload['distributor_id'] : 0,
                'status' => $payload['status'],
            ]);
        } catch (\Throwable $e) {
            error_log('DDM user create failed: ' . $e->getMessage());
            header('Location: /users?message=' . urlencode('Unable to create user. Please check the details and try again.'));
            exit;
        }

        header('Location: /users?message=' . urlencode('User created.'));
        exit;
    }

    public function update(array $request, array $config): void
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canWrite($authUser)) {
            Auth::forbidden();
        }

        $repo = new UserRepository(Database::pdo($config));
        if (!$repo->usersTableExists()) {
            header('Location: /users?message=' . urlencode('Users table not found. Apply RBAC migration first.'));
            exit;
        }

        $payload = $this->sanitizePayload($request['body'] ?? []);
        $userId = (int)($request['body']['id'] ?? 0);
        if ($userId <= 0 || $repo->findUserById($userId) === null) {
            header('Location: /users?message=' . urlencode('User not found.'));
            exit;
        }

        $validation = $this->validatePayload($repo, $payload, false);
        if ($validation !== null) {
            header('Location: /users?edit=' . $userId . '&message=' . urlencode($validation));
            exit;
        }

        try {
            $repo->updateUser($userId, [
                'email' => $payload['email'],
                'display_name' => $payload['display_name'],
                'role' => $payload['role'],
                'distributor_id' => $payload['role'] === Auth::ROLE_AGENT_VIEWER ? $payload['distributor_id'] : 0,
                'status' => $payload['status'],
            ]);

            if ($payload['password'] !== '') {
                $repo->updatePasswordHash($userId, password_hash($payload['password'], PASSWORD_DEFAULT));
            }
        } catch (\Throwable $e) {
            error_log('DDM user update failed for user #' . $userId . ': ' . $e->getMessage());
            header('Location: /users?edit=' . $userId . '&message=' . urlencode('Unable to update user. Please check the details and try again.'));
            exit;
        }

        header('Location: /users?message=' . urlencode('User updated.'));
        exit;
    }

    private function sanitizePayload(array $body): array
    {
        return [
            'email' => strtolower(trim((string)($body['email'] ?? ''))),
            'password' => (string)($body['password'] ?? ''),
            'display_name' => trim((string)($body['display_name'] ?? '')),
            'role' => trim((string)($body['role'] ?? '')),
            'distributor_id' => (int)($body['distributor_id'] ?? 0),
            'status' => ((int)($body['status'] ?? 1)) === 1 ? 1 : 0,
        ];
    }

    private function validatePayload(UserRepository $repo, array $payload, bool $creating): ?string
    {
        if ($payload['email'] === '' || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }

        $validRoles = [
            Auth::ROLE_INTERNAL_ADMIN,
            Auth::ROLE_INTERNAL_READ_ONLY,
            Auth::ROLE_AGENT_VIEWER,
        ];
        if (!in_array($payload['role'], $validRoles, true)) {
            return 'Choose a valid role.';
        }

        if ($creating && trim($payload['password']) === '') {
            return 'Password is required when creating a user.';
        }

        if (trim($payload['password']) !== '' && strlen($payload['password']) < 8) {
            return 'Password must be at least 8 characters.';
        }

        if ($payload['role'] === Auth::ROLE_AGENT_VIEWER) {
            if ($payload['distributor_id'] <= 0) {
                return 'Agent viewer accounts must be linked to a distributor.';
            }
            if ($repo->distributorIsInternalOnly($payload['distributor_id'])) {
                return 'Agent viewer accounts cannot be linked to internal-only distributors.';
            }
        }

        return null;
    }
}
