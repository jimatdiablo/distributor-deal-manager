<?php
/**
 * maat-php — the PHP implementation of the Maat client contract (spec §3.0, §3.2).
 *
 * Zero dependencies: ext-sodium (PHP ≥ 7.2) for Ed25519, ext-curl, ext-json.
 * Drop-in for legacy apps:
 *
 *     require '/path/to/maat-php/src/Maat.php';
 *     Maat::configure([
 *         'maat_url'      => 'https://maat.example.com',
 *         'app'           => 'legacy-billing',
 *         'namespace'     => 'billing',
 *         'service_token' => 'maat_svc_...',
 *     ]);
 *     $identity = Maat::user();                         // verified or silently refreshed
 *     $localUser = $users->findEnabledByEmail($identity->email);
 *     if ($localUser === null) { http_response_code(403); exit; }
 *     $theme = Maat::prefs($identity)->global('theme', 'system');
 *
 * A MaatUser proves control of an email address; it never grants admission to
 * the PHP application. The application must match an eligible local user and
 * derive its session and permissions from that local record.
 *
 * The availability doctrine (spec §3.1) applies identically here: token
 * verification never touches the network on the hot path (JWKS is cached in
 * a temp file, refreshed once on unknown kid), and prefs reads degrade to
 * stale-cache-then-defaults on any failure within a 500ms budget.
 */

class Maat
{
    public const COOKIE = 'maat_token';
    public const BROKER_COOKIE = 'maat_broker_session';
    private const LEEWAY_S = 60;

    private static $config = [];
    /** @var callable|null test seam: fn(string $url): ?string (body or null) */
    public static $httpGet = null;
    /** @var callable|null test seam: fn(string $url, array $headers, string $body): ?array [status, body] */
    public static $httpRequest = null;

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function config(string $key, $default = null)
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * The verified current user. An expired access token is refreshed by this
     * backend using the opaque broker cookie. Null means the consumer should
     * render its own inline sign-in; this method never redirects. A non-null
     * result proves email control only. The consumer must still find an
     * eligible user in its own local table before granting access.
     */
    public static function user(array $opts = []): ?MaatUser
    {
        $jwt = $_COOKIE[self::COOKIE] ?? null;
        $claims = $jwt ? self::verifyToken($jwt) : null;

        if ($claims !== null) {
            return new MaatUser($claims);
        }

        $brokerSession = $_COOKIE[self::BROKER_COOKIE] ?? null;
        if (is_string($brokerSession)) {
            $tokens = self::brokerRequest('/api/v1/broker/refresh', [
                'broker_session' => $brokerSession,
            ]);

            if ($tokens !== null && isset($tokens['access_token'], $tokens['broker_session'])) {
                $claims = self::verifyToken($tokens['access_token']);
                if ($claims !== null) {
                    self::installCookies($tokens);
                    return new MaatUser($claims);
                }
            }
        }

        return null;
    }

    /**
     * Request a one-time 6-digit code email without revealing membership.
     * Sign-in is code-only (spec §8.1): the email carries no link, so the
     * user always finishes on the host and device they started on.
     */
    public static function requestSignIn(string $email): bool
    {
        return self::requestSignInResult($email)->ok;
    }

    /**
     * Diagnostic variant of requestSignIn(). It never reveals whether the
     * email exists, but lets consumers distinguish transport, authentication,
     * HTTP, and response-shape failures.
     */
    public static function requestSignInResult(string $email): MaatBrokerResult
    {
        return self::brokerRequestResult('/api/v1/broker/sign-in/request', [
            'email' => $email,
        ]);
    }

    /** Exchange a code submitted to the consumer's inline sign-in form. */
    public static function exchangeCode(string $email, string $code, array $opts = []): ?MaatUser
    {
        return self::finishExchange([
            'email' => $email,
            'code' => $code,
            'session_name' => $opts['session_name'] ?? null,
        ]);
    }

    /** Revoke this browser's broker session and clear both parent-domain cookies. */
    public static function signOut(): bool
    {
        $session = $_COOKIE[self::BROKER_COOKIE] ?? '';
        $ok = self::brokerRequest('/api/v1/broker/revoke', ['broker_session' => $session], [204]) !== null;
        self::clearCookies();
        return $ok;
    }

    public static function prefs(MaatUser $user): MaatPrefs
    {
        return new MaatPrefs($user);
    }

    /**
     * Verify a compact EdDSA JWT against cached JWKS (spec §3.0):
     * signature + exp with 60s leeway; one JWKS refresh on unknown kid.
     * Returns the claims array or null. Never throws.
     */
    public static function verifyToken(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $header = json_decode(self::b64urlDecode($h) ?? '', true);
        $claims = json_decode(self::b64urlDecode($p) ?? '', true);
        $sig = self::b64urlDecode($s);

        if (!is_array($header) || !is_array($claims) || $sig === null) {
            return null;
        }
        $kid = $header['kid'] ?? null;
        if ($kid === null || ($header['alg'] ?? '') !== 'EdDSA') {
            return null;
        }

        $key = self::keyFor($kid);
        if ($key === null) {
            return null;
        }

        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        if (!sodium_crypto_sign_verify_detached($sig, "$h.$p", $key)) {
            return null;
        }

        $exp = $claims['exp'] ?? 0;
        if (!is_int($exp) || $exp + self::LEEWAY_S <= time()) {
            return null;
        }

        return $claims;
    }

    // ── JWKS cache (file-backed: survives across FPM workers, spec §3.2) ──

    private static function keyFor(string $kid): ?string
    {
        $jwks = self::cachedJwks();
        if (!isset($jwks[$kid])) {
            self::refreshJwks();
            $jwks = self::cachedJwks();
        }

        return isset($jwks[$kid]) ? self::b64urlDecode($jwks[$kid]) : null;
    }

    private static function jwksCachePath(): string
    {
        return sys_get_temp_dir() . '/maat_jwks_' . md5(self::jwksUrl()) . '.json';
    }

    /** @return array<string,string> kid => base64url raw public key */
    private static function cachedJwks(): array
    {
        $raw = @file_get_contents(self::jwksCachePath());
        $decoded = $raw !== false ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public static function refreshJwks(): bool
    {
        $body = self::httpGet(self::jwksUrl());
        $parsed = $body !== null ? json_decode($body, true) : null;
        if (!is_array($parsed) || !isset($parsed['keys'])) {
            return false;
        }

        $jwks = [];
        foreach ($parsed['keys'] as $key) {
            if (($key['kty'] ?? '') === 'OKP' && ($key['crv'] ?? '') === 'Ed25519') {
                $jwks[$key['kid']] = $key['x']; // raw 32-byte key, base64url
            }
        }

        @file_put_contents(self::jwksCachePath(), json_encode($jwks), LOCK_EX);

        return true;
    }

    /** Test seam. */
    public static function clearJwksCache(): void
    {
        @unlink(self::jwksCachePath());
    }

    // ── tiny HTTP (configurable budget, spec §3.0) ───────────────────────

    /** Server/container-reachable Maat base URL; maat_url is the legacy fallback. */
    public static function internalUrl(): string
    {
        return rtrim((string) self::config('internal_url', self::config('maat_url', '')), '/');
    }

    /** Explicit JWKS endpoint, or the well-known path on internalUrl(). */
    public static function jwksUrl(): string
    {
        $configured = self::config('jwks_url');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return self::internalUrl() . '/.well-known/jwks.json';
    }

    public static function timeoutMs(): int
    {
        $configured = (int) self::config('timeout_ms', 500);

        return $configured > 0 ? $configured : 500;
    }

    public static function httpGet(string $url): ?string
    {
        if (self::$httpGet !== null) {
            return (self::$httpGet)($url);
        }

        [$status, $body] = self::curl('GET', $url, self::internalForwardedHeaders($url), null);

        return $status === 200 ? $body : null;
    }

    /**
     * @return array{0:int,1:string,2?:int,3?:string}
     *   [status, body, optional curl errno, optional curl error]
     */
    public static function httpRequest(string $method, string $url, array $headers, ?string $body): array
    {
        if (self::$httpRequest !== null) {
            return (self::$httpRequest)($method, $url, $headers, $body);
        }

        return self::curl($method, $url, $headers, $body);
    }

    /** @return ?array decoded JSON, or [] for a successful empty response */
    public static function brokerRequest(string $path, array $payload, array $success = [200, 202]): ?array
    {
        $result = self::brokerRequestResult($path, $payload, $success);

        return $result->ok ? $result->data : null;
    }

    /** Structured broker response used by diagnostic consumer flows. */
    public static function brokerRequestResult(
        string $path,
        array $payload,
        array $success = [200, 202]
    ): MaatBrokerResult {
        $payload = array_filter($payload, static fn ($value) => $value !== null);
        $encoded = json_encode($payload);
        if ($encoded === false) {
            return new MaatBrokerResult(false, 0, 'invalid_payload', json_last_error_msg(), null);
        }

        $response = self::httpRequest(
            'POST',
            self::internalUrl() . $path,
            [
                'Authorization: Bearer ' . self::config('service_token'),
                'Content-Type: application/json',
                'X-Forwarded-Proto: https',
            ],
            $encoded
        );
        $status = (int) ($response[0] ?? 0);
        $body = (string) ($response[1] ?? '');
        $curlErrno = (int) ($response[2] ?? 0);
        $curlError = (string) ($response[3] ?? '');

        if ($status === 0) {
            $error = $curlErrno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport_error';
            $message = $curlError !== '' ? $curlError : 'Maat request failed before an HTTP response';

            return new MaatBrokerResult(false, 0, $error, $message, null);
        }

        if (!in_array($status, $success, true)) {
            $decoded = json_decode($body, true);
            $message = is_array($decoded) && is_string($decoded['error'] ?? null)
                ? $decoded['error']
                : "Maat returned HTTP $status";
            $error = in_array($status, [401, 403], true)
                ? 'unauthorized'
                : ($status === 400 ? 'invalid_request' : 'http_error');

            return new MaatBrokerResult(false, $status, $error, $message, null);
        }

        if ($body === '') {
            return new MaatBrokerResult(true, $status, null, null, []);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new MaatBrokerResult(
                false,
                $status,
                'malformed_response',
                'Maat returned a successful response with invalid JSON',
                null
            );
        }

        return new MaatBrokerResult(true, $status, null, null, $decoded);
    }

    private static function finishExchange(array $payload): ?MaatUser
    {
        $tokens = self::brokerRequest('/api/v1/broker/exchange', $payload);
        if ($tokens === null || !isset($tokens['access_token'], $tokens['broker_session'])) {
            return null;
        }

        $claims = self::verifyToken($tokens['access_token']);
        if ($claims === null) {
            return null;
        }

        self::installCookies($tokens);
        return new MaatUser($claims);
    }

    private static function installCookies(array $tokens): void
    {
        self::setSecureCookie(self::COOKIE, $tokens['access_token'], (int) ($tokens['expires_in'] ?? 900));
        self::setSecureCookie(self::BROKER_COOKIE, $tokens['broker_session'], 30 * 24 * 60 * 60);
        $_COOKIE[self::COOKIE] = $tokens['access_token'];
        $_COOKIE[self::BROKER_COOKIE] = $tokens['broker_session'];
    }

    private static function clearCookies(): void
    {
        self::setSecureCookie(self::COOKIE, '', -3600);
        self::setSecureCookie(self::BROKER_COOKIE, '', -3600);
        unset($_COOKIE[self::COOKIE], $_COOKIE[self::BROKER_COOKIE]);
    }

    /**
     * The host the browser used to reach this app: proxy-forwarded host
     * first (first value of a comma-separated chain), then the direct Host
     * header, lowercased and stripped of any port.
     */
    public static function requestHost(): ?string
    {
        $raw = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
        $host = strtolower(trim(explode(',', (string) $raw)[0]));
        if ($host === '') {
            return null;
        }

        $colon = strrpos($host, ':');
        if ($colon !== false && ctype_digit(substr($host, $colon + 1))) {
            $host = substr($host, 0, $colon);
        }

        return $host;
    }

    /**
     * The cookie Domain for this request: the longest entry in the
     * `cookie_domains` config (legacy `cookie_domain` accepted) that
     * contains the request host. Apps served on several FQDNs (internal +
     * public) list one base domain per world; the SSO cookie is then always
     * scoped to the world the user is actually on. Null means host-only —
     * the safe fallback for hosts outside every configured base, and
     * strictly better than the browser silently rejecting a mismatched
     * Domain (which is how sessions used to vanish).
     */
    public static function cookieDomain(): ?string
    {
        $domains = self::config('cookie_domains');
        if (!is_array($domains)) {
            $legacy = self::config('cookie_domain');
            $domains = $legacy ? [$legacy] : [];
        }

        $host = self::requestHost();
        $best = null;
        foreach ($domains as $domain) {
            $base = strtolower(ltrim((string) $domain, '.'));
            if ($base === '' || $host === null) {
                continue;
            }
            $suffix = '.' . $base;
            $within = $host === $base
                || (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix);
            if ($within && ($best === null || strlen($base) > strlen($best))) {
                $best = $base;
            }
        }

        return $best === null ? null : '.' . $best;
    }

    private static function setSecureCookie(string $name, string $value, int $maxAge): void
    {
        $options = [
            'expires' => time() + $maxAge,
            'path' => '/',
            'secure' => self::config('secure_cookies', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        $domain = self::cookieDomain();
        if ($domain !== null) {
            $options['domain'] = $domain;
        }

        setcookie($name, $value, $options);
    }

    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT_MS => self::timeoutMs(),
            CURLOPT_CONNECTTIMEOUT_MS => self::timeoutMs(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            $response === false ? 0 : $status,
            $response === false ? '' : $response,
            $curlErrno,
            $curlError,
        ];
    }

    private static function internalForwardedHeaders(string $url): array
    {
        $internal = self::internalUrl();
        if ($internal !== '' && strncmp($url, $internal, strlen($internal)) === 0) {
            return ['X-Forwarded-Proto: https'];
        }

        return [];
    }

    public static function b64urlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}

/** Structured broker-call outcome; safe to log because it never reports email membership. */
class MaatBrokerResult
{
    public $ok;
    public $status;
    public $error;
    public $message;
    public $data;

    public function __construct(
        bool $ok,
        int $status,
        ?string $error,
        ?string $message,
        ?array $data
    ) {
        $this->ok = $ok;
        $this->status = $status;
        $this->error = $error;
        $this->message = $message;
        $this->data = $data;
    }
}

/**
 * Verified identity claims (spec §2.3). Possession of this object is not
 * application admission; consumers must authorize through their local users.
 */
class MaatUser
{
    public $sub;
    public $email;
    public $name;
    public $tz;
    public $locale;
    public $theme;
    public $org;
    public $orgRole;
    /** @var array<string,string> slug => role */
    public $teams;
    public $claims;

    public function __construct(array $claims)
    {
        $this->sub = $claims['sub'] ?? null;
        $this->email = $claims['email'] ?? null;
        $this->name = $claims['name'] ?? null;
        $this->tz = $claims['tz'] ?? 'Etc/UTC';
        $this->locale = $claims['locale'] ?? 'en-US';
        $this->theme = $claims['theme'] ?? 'system';
        $this->org = $claims['org'] ?? null;
        $this->orgRole = $claims['org_role'] ?? 'member';
        $this->teams = $claims['teams'] ?? [];
        $this->claims = $claims;
    }

    public function teamRole(string $slug): ?string
    {
        return $this->teams[$slug] ?? null;
    }

    public function isMember(string $slug): bool
    {
        return array_key_exists($slug, $this->teams);
    }

    public function isOrgAdmin(): bool
    {
        return $this->orgRole === 'admin';
    }
}

/**
 * Preferences client (spec §2.8, §3.0): 60s file cache, write-through with
 * If-Match, defaults-on-failure. Reads never throw; a Maat outage means
 * defaults, not errors.
 */
class MaatPrefs
{
    private const TTL_S = 60;

    private $user;

    public function __construct(MaatUser $user)
    {
        $this->user = $user;
    }

    public function get(string $key, $default = null)
    {
        $ns = Maat::config('namespace');
        $settings = $this->namespaceSettings($ns);

        return $settings[$key] ?? $default;
    }

    public function global(string $key, $default = null)
    {
        $settings = $this->namespaceSettings('global');

        return $settings[$key] ?? $default;
    }

    /** Write-through merge. Returns true on success — writes MAY fail visibly. */
    public function put(string $key, $value): bool
    {
        $ns = Maat::config('namespace');
        $current = $this->fetch($ns);
        if ($current === null) {
            return false;
        }

        $settings = $current['settings'];
        $settings[$key] = $value;

        [$status, $body] = Maat::httpRequest(
            'PUT',
            $this->url($ns),
            array_merge($this->headers(), [
                'Content-Type: application/json',
                'If-Match: ' . $current['version'],
            ]),
            json_encode(['settings' => $settings])
        );

        if ($status !== 200) {
            return false;
        }

        $parsed = json_decode($body, true);
        if (is_array($parsed)) {
            $this->cachePut($ns, $parsed['settings'], (int) $parsed['version']);
        }

        return true;
    }

    /** Cache → network → stale → empty (the doctrine, spec §3.1). */
    private function namespaceSettings(string $ns): array
    {
        $cached = $this->cacheGet($ns);

        if ($cached !== null && $cached['fresh']) {
            return $cached['settings'];
        }

        $fetched = $this->fetch($ns);
        if ($fetched !== null) {
            $this->cachePut($ns, $fetched['settings'], $fetched['version']);

            return $fetched['settings'];
        }

        // stale beats defaults, defaults beat errors
        return $cached !== null ? $cached['settings'] : [];
    }

    /** @return ?array{settings:array,version:int} */
    private function fetch(string $ns): ?array
    {
        [$status, $body] = Maat::httpRequest('GET', $this->url($ns), $this->headers(), null);
        if ($status !== 200) {
            return null;
        }

        $parsed = json_decode($body, true);
        if (!is_array($parsed) || !isset($parsed['settings'])) {
            return null;
        }

        return ['settings' => $parsed['settings'], 'version' => (int) ($parsed['version'] ?? 0)];
    }

    private function url(string $ns): string
    {
        return Maat::internalUrl() . '/api/v1/users/' . rawurlencode($this->user->sub) . '/prefs/' . rawurlencode($ns);
    }

    private function headers(): array
    {
        return ['Authorization: Bearer ' . Maat::config('service_token')];
    }

    // file cache: survives across FPM workers (spec §3.2)

    private function cachePath(string $ns): string
    {
        return sys_get_temp_dir() . '/maat_prefs_' . md5($this->user->sub . '|' . $ns) . '.json';
    }

    /** @return ?array{settings:array,fresh:bool} */
    private function cacheGet(string $ns): ?array
    {
        $raw = @file_get_contents($this->cachePath($ns));
        if ($raw === false) {
            return null;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['settings'], $entry['at'])) {
            return null;
        }

        return [
            'settings' => $entry['settings'],
            'fresh' => (time() - (int) $entry['at']) < self::TTL_S,
        ];
    }

    public function cachePut(string $ns, array $settings, int $version): void
    {
        @file_put_contents(
            $this->cachePath($ns),
            json_encode(['settings' => $settings, 'version' => $version, 'at' => time()]),
            LOCK_EX
        );
    }

    /** Test seam. */
    public function cacheClear(string $ns): void
    {
        @unlink($this->cachePath($ns));
    }

    /** Test seam: age the cache entry past the TTL. */
    public function cacheAge(string $ns, int $seconds): void
    {
        $raw = @file_get_contents($this->cachePath($ns));
        $entry = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($entry)) {
            $entry['at'] = time() - $seconds;
            @file_put_contents($this->cachePath($ns), json_encode($entry), LOCK_EX);
        }
    }
}
