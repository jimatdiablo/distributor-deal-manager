<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Repositories\UserRepository;

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

$options = getopt('', ['email:', 'password:', 'name::']);
$email = strtolower(trim((string)($options['email'] ?? getenv('DDM_ADMIN_EMAIL') ?: '')));
$password = (string)($options['password'] ?? getenv('DDM_ADMIN_PASSWORD') ?: '');
$displayName = trim((string)($options['name'] ?? getenv('DDM_ADMIN_NAME') ?: 'Initial Admin'));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Provide a valid admin email with --email or DDM_ADMIN_EMAIL.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Provide an admin password of at least 8 characters with --password or DDM_ADMIN_PASSWORD.\n");
    exit(1);
}

$config = require $projectRoot . '/config/app.php';
$pdo = Database::pdo($config);

$tableCheck = $pdo->prepare('SHOW TABLES LIKE :table_name');
$tableCheck->bindValue(':table_name', 'users');
$tableCheck->execute();
if (!$tableCheck->fetchColumn()) {
    fwrite(STDERR, "The users table does not exist. Apply the RBAC schema/migration first.\n");
    exit(1);
}

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'internal_admin' AND status = 1");
$activeAdminCount = (int)$stmt->fetchColumn();
if ($activeAdminCount > 0) {
    fwrite(STDOUT, "Active internal_admin user already exists. No bootstrap user created.\n");
    exit(0);
}

$repo = new UserRepository($pdo);
$repo->createUser([
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'display_name' => $displayName,
    'role' => Auth::ROLE_INTERNAL_ADMIN,
    'distributor_id' => 0,
    'status' => 1,
]);

fwrite(STDOUT, "Created initial internal_admin user: {$email}\n");
