<?php
declare(strict_types=1);
// Full browser flow against a loopback-only fixture broker, never sends email.
require_once __DIR__ . '/../src/Core/Database.php';
if (getenv('DB_HOST') !== 'mysql') { throw new RuntimeException('Local Compose only.'); }
$root = dirname(__DIR__);
$pdo = App\Core\Database::pdo([]);
$email = 'maat-http-' . bin2hex(random_bytes(6)) . '@example.com';
$stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role, status, display_name) VALUES (?, ?, ?, 1, ?)');
$stmt->execute([$email, password_hash('fixture-only', PASSWORD_DEFAULT), 'internal_read_only', 'Maat HTTP fixture']);
$id = (int)$pdo->lastInsertId();
$dir = sys_get_temp_dir() . '/ddm-http-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$server = null;
try {
    $pair = sodium_crypto_sign_keypair();
    $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $input = $b64(json_encode(['alg' => 'EdDSA', 'kid' => 'http-test'])) . '.' . $b64(json_encode(['sub' => 'http-test', 'email' => $email, 'exp' => time() + 900]));
    $jwt = $input . '.' . $b64(sodium_crypto_sign_detached($input, sodium_crypto_sign_secretkey($pair)));
    $jwks = json_encode(['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'http-test', 'x' => $b64(sodium_crypto_sign_publickey($pair))]]]);
    $prefix = '<?php require ' . var_export($root . '/src/Core/MaatClient.php', true) . ';' . "\n";
    $prefix .= 'Maat::$httpGet = static fn($url) => ' . var_export($jwks, true) . ';' . "\n";
    $prefix .= '$fixtureJwt = ' . var_export($jwt, true) . ';' . "\n";
    $prefix .= '$brokerLog = ' . var_export($dir . '/broker.log', true) . ';' . "\n";
    $prefix .= <<<'PHP'
Maat::$httpRequest = static function ($method, $url, $headers, $body) use ($fixtureJwt, $brokerLog) {
    file_put_contents($brokerLog, $url . "\n", FILE_APPEND);
    if (str_ends_with($url, '/request')) { return [202, '{}']; }
    if (str_ends_with($url, '/revoke')) { return [204, '']; }
    $payload = json_decode($body, true);
    if (($payload['code'] ?? '') !== '123456') { return [401, '{}']; }
    return [200, json_encode(['access_token' => $fixtureJwt, 'broker_session' => 'fixture-broker', 'expires_in' => 900])];
};
PHP;
    file_put_contents($dir . '/router.php', $prefix . "\nrequire " . var_export($root . '/public/index.php', true) . ';');
    $env = array_merge(getenv(), ['MAAT_ENABLED' => '1', 'MAAT_INTERNAL_URL' => 'http://fixture.invalid/' . basename($dir), 'MAAT_SERVICE_TOKEN' => 'test-only', 'MAAT_SECURE_COOKIES' => '0', 'MAAT_COOKIE_DOMAINS' => '']);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:18081', $dir . '/router.php'], [['pipe', 'r'], ['file', $dir . '/server.log', 'a'], ['file', $dir . '/server.log', 'a']], $pipes, $root, $env);
    if (!is_resource($server)) { throw new RuntimeException('Cannot start fixture server'); }
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', 18081);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    $request = static function (string $path, ?array $data = null) use ($dir): array {
        $ch = curl_init('http://127.0.0.1:18081' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $dir . '/cookies', CURLOPT_COOKIEFILE => $dir . '/cookies', CURLOPT_TIMEOUT => 5]);
        if ($data !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $body];
    };
    $check = static function (bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
        echo "PASS: $message\n";
    };
    [$status, $body] = $request('/login');
    $check($status === 200 && str_contains($body, 'id="signin-email"') && !str_contains($body, 'id="signin-code"') && !str_contains($body, 'name="password"'), 'Step one shows only the email form');
    preg_match('/name="_csrf_token" value="([^"]+)"/', $body, $match);
    $csrf = $match[1];
    $check($request('/login', ['action' => 'request', 'email' => $email])[0] === 419, 'Email request requires CSRF');
    [$status, $body] = $request('/login', ['_csrf_token' => $csrf, 'action' => 'request', 'email' => 'unmapped@example.com']);
    $check($status === 200 && str_contains($body, 'If this email is mapped') && !file_exists($dir . '/broker.log'), 'Unmapped account never triggers Maat mail');
    $pdo->exec("UPDATE users SET status = 0 WHERE id = $id");
    $request('/login', ['_csrf_token' => $csrf, 'action' => 'request', 'email' => $email]);
    $check(!file_exists($dir . '/broker.log'), 'Disabled account never triggers Maat mail');
    $pdo->exec("UPDATE users SET status = 1 WHERE id = $id");
    [$status, $body] = $request('/login', ['_csrf_token' => $csrf, 'action' => 'request', 'email' => $email]);
    $check($status === 200 && str_contains($body, 'id="signin-code"') && !str_contains($body, 'id="signin-email"') && str_contains($body, 'Send a new code'), 'Step two replaces email form and offers resend');
    $request('/login', ['_csrf_token' => $csrf, 'action' => 'request', 'email' => $email]);
    $check(count(file($dir . '/broker.log')) === 2, 'Resend requests a new code for the same mapped account');
    [$status, $body] = $request('/login', ['_csrf_token' => $csrf, 'action' => 'verify', 'code' => '000000']);
    $check($status === 200 && str_contains($body, 'Unable to sign in'), 'Wrong code cannot authenticate');
    $check($request('/')[0] === 302, 'Unauthenticated dashboard redirects');
    $check($request('/login', ['_csrf_token' => $csrf, 'action' => 'verify', 'code' => '123456'])[0] === 302, 'Valid code redirects to dashboard');
    $check($request('/')[0] === 200, 'Verified Maat user reaches dashboard');
    $check($request('/users')[0] === 403, 'Read-only user cannot manage accounts');
    // Simulate expiry/loss of the shared cookies after the application session
    // has been established. G2 retains that application session until logout.
    $cookieFile = $dir . '/cookies';
    $cookies = file($cookieFile);
    file_put_contents($cookieFile, implode('', array_filter($cookies, static fn($line) => !str_contains($line, "\tmaat_token\t") && !str_contains($line, "\tmaat_broker_session\t"))));
    $check($request('/')[0] === 200, 'Established DDM session survives missing shared Maat cookies like G2');
    $pdo->exec("UPDATE users SET status = 0 WHERE id = $id");
    $check($request('/')[0] === 200, 'Established session retains its account mapping like G2');
    $check($request('/logout', ['_csrf_token' => $csrf])[0] === 302, 'Logout redirects to sign-in');
    $check($request('/')[0] === 302, 'Logout clears Maat authentication');
    // A fresh app session must map an active local account from shared identity.
    file_put_contents($cookieFile, "# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t0\tmaat_token\t$jwt\n");
    $check($request('/')[0] === 302, 'Disabled account cannot hydrate a fresh session from shared identity');
    $pdo->exec("UPDATE users SET status = 1 WHERE id = $id");
    $check($request('/')[0] === 200, 'Existing shared Maat identity hydrates a new DDM session without a code');
    echo "Maat browser-flow checks passed. No mail sent.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}
