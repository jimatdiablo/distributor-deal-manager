<?php
declare(strict_types=1);
ob_start();

// Local Docker only. Every fixture is rolled back, including on failure.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});
use App\Core\Auth;
use App\Core\Database;
use App\Core\MaatAuth;
use App\Repositories\UserRepository;

function check(bool $ok, string $description): void {
    if (!$ok) { throw new RuntimeException($description); }
    echo "PASS: $description\n";
}
if (getenv('DB_HOST') !== 'mysql') {
    throw new RuntimeException('Run only inside the local Compose app, never a deployed environment.');
}
$config = require dirname(__DIR__) . '/config/app.php';
$pdo = Database::pdo($config);
$pdo->beginTransaction();
try {
    $email = 'maat-test-' . bin2hex(random_bytes(6)) . '@example.com';
    $insert = $pdo->prepare('INSERT INTO users (email, password_hash, role, status, display_name) VALUES (?, ?, ?, ?, ?)');
    $insert->execute([$email, password_hash('test-only', PASSWORD_DEFAULT), Auth::ROLE_INTERNAL_READ_ONLY, 1, 'Maat test']);
    $id = (int)$pdo->lastInsertId();
    $repo = new UserRepository($pdo);
    check(MaatAuth::eligibleUser($repo, strtoupper($email))['id'] == $id, 'Maat identity matches local email case-insensitively');
    check(!isset(MaatAuth::eligibleUser($repo, $email)['password_hash']), 'Session identity excludes password hash');
    check(!Auth::canWrite(MaatAuth::eligibleUser($repo, $email)), 'Read-only identity cannot write');
    check(MaatAuth::eligibleUser($repo, 'absent@example.com') === null, 'Unknown Maat identities receive no DDM account');
    $pdo->exec("UPDATE users SET status = 0 WHERE id = $id");
    check(MaatAuth::eligibleUser($repo, $email) === null, 'Disabled local account denied');
    $pdo->exec("UPDATE users SET status = 1, role = 'agent_viewer', distributor_id = NULL WHERE id = $id");
    check(MaatAuth::eligibleUser($repo, $email) === null, 'Unscoped agent viewer denied');
    $pdo->prepare('INSERT INTO distributors (name, internal_only) VALUES (?, 0)')->execute(['Maat fixture distributor']);
    $distributorId = (int)$pdo->lastInsertId();
    $pdo->exec("UPDATE users SET distributor_id = $distributorId WHERE id = $id");
    $agent = MaatAuth::eligibleUser($repo, $email);
    check($agent !== null && $agent['distributor_id'] == $distributorId && !Auth::canWrite($agent), 'Agent retains exactly its external distributor and cannot write');
    $pdo->exec("UPDATE users SET role = 'internal_admin', distributor_id = NULL WHERE id = $id");
    check(Auth::canWrite(MaatAuth::eligibleUser($repo, $email)), 'Local admin retains administrative access');
    $pdo->exec("UPDATE users SET role = 'agent_viewer' WHERE id = $id");
    $internal = $pdo->query('SELECT id FROM distributors WHERE internal_only = 1 LIMIT 1')->fetchColumn();
    if ($internal !== false) {
        $pdo->exec("UPDATE users SET distributor_id = " . (int)$internal . " WHERE id = $id");
        check(MaatAuth::eligibleUser($repo, $email) === null, 'Internal distributor cannot grant agent access');
    }

    MaatAuth::configure();
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $secret = sodium_crypto_sign_secretkey($pair);
    $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $url = 'http://fixture.invalid/' . bin2hex(random_bytes(8));
    Maat::configure(['internal_url' => $url, 'service_token' => 'fixture-only', 'secure_cookies' => false]);
    Maat::$httpGet = static fn(string $url): string => json_encode(['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'test', 'x' => $encode($public)]]]);
    $token = static function (array $claims) use ($encode, $secret): string {
        $input = $encode(json_encode(['alg' => 'EdDSA', 'kid' => 'test'])) . '.' . $encode(json_encode($claims));
        return $input . '.' . $encode(sodium_crypto_sign_detached($input, $secret));
    };
    $jwt = $token(['sub' => 'test-user', 'email' => $email, 'exp' => time() + 900]);
    check(Maat::verifyToken($jwt)['email'] === $email, 'Signed Ed25519 identity accepted');
    check(Maat::verifyToken($jwt . 'bad') === null, 'Tampered signature denied');
    check(Maat::verifyToken($token(['exp' => time() - 120])) === null, 'Expired identity denied');
    Maat::$httpRequest = static fn($method, $url, $headers, $body): array => [401, '{"error":"invalid code"}'];
    check(Maat::exchangeCode($email, '000000') === null, 'Invalid email code denied');
    Maat::$httpRequest = static fn($method, $url, $headers, $body): array => [200, json_encode(['access_token' => $jwt, 'broker_session' => 'test-broker', 'expires_in' => 900])];
    check(Maat::exchangeCode($email, '123456')->email === $email, 'Valid code exchange installs verified identity');
    $_COOKIE[Maat::COOKIE] = 'expired';
    check(Maat::user()->email === $email, 'Broker session refresh restores verified identity');
    $_SESSION = [];
    check(!Auth::validateCsrfToken('wrong'), 'CSRF rejects invalid form submission');
    check(Auth::validateCsrfToken(Auth::csrfToken()), 'CSRF accepts session token');
    Maat::clearJwksCache();
    putenv('MAAT_COOKIE_DOMAINS=.prd.dd.corp');
    MaatAuth::configure();
    $_SERVER['HTTP_HOST'] = 'ddm.prd.dd.corp';
    check(Maat::cookieDomain() === '.prd.dd.corp', 'DDM scopes SSO cookies to the production base domain');
    $_SERVER['HTTP_HOST'] = 'g2.prd.dd.corp';
    check(Maat::cookieDomain() === '.prd.dd.corp', 'Sibling production apps share the same Maat cookie domain');
    $_SERVER['HTTP_HOST'] = 'ddm.stg.dd.corp';
    check(Maat::cookieDomain() === null, 'Production SSO cookie does not cross into staging');
} finally {
    $pdo->rollBack();
}
echo "Maat authentication checks passed; fixtures rolled back.\n";
