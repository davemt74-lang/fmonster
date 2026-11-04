<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMError extends RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public array $fields = []) { parent::__construct($message); }
}

final class FMSecurity
{
    private static array $config = [];

    public static function configure(array $config): void { self::$config = $config; }
    public static function clientIp(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'); }
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) { return ''; }
        $scheme = strtolower((string)$parts['scheme']);
        $port = isset($parts['port']) && !(($scheme === 'https' && $parts['port'] === 443) || ($scheme === 'http' && $parts['port'] === 80)) ? ':' . $parts['port'] : '';
        return $scheme . '://' . strtolower((string)$parts['host']) . $port;
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        $production = (self::$config['app_env'] ?? 'production') === 'production';
        $secure = $production || str_starts_with((string)self::$config['app_url'], 'https://');
        if ($production && !self::isHttpsRequest()) { throw new FMError(426, 'https_required', 'A secure connection is required. Please open the HTTPS website.'); }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)(self::$config['session']['lifetime'] ?? 28800));
        $savePath = (string)(self::$config['session']['save_path'] ?? '');
        if ($savePath !== '') {
            if (!is_dir($savePath) && !mkdir($savePath, 0700, true) && !is_dir($savePath)) { throw new RuntimeException('Session storage is unavailable.'); }
            session_save_path($savePath);
        }
        session_name($secure ? '__Host-foodmonster' : 'foodmonster_development');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start()) { throw new RuntimeException('Could not start a secure session.'); }
        if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    }

    public static function isHttpsRequest(): bool { return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443; }
    public static function csrf(): string { return (string)($_SESSION['csrf'] ?? ''); }

    public static function checkMutation(): void
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && !hash_equals(self::origin((string)self::$config['app_url']), self::origin($origin))) { throw new FMError(403, 'origin_rejected', 'This request came from an unrecognized website.'); }
        if (strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') { throw new FMError(403, 'origin_rejected', 'Cross-site requests are not permitted.'); }
        $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($provided === '' || self::csrf() === '' || !hash_equals(self::csrf(), $provided)) { throw new FMError(419, 'csrf_invalid', 'Your form session has expired. Refresh the page and try again.'); }
    }

    public static function rotateSession(?array $user = null): void
    {
        if (!session_regenerate_id(true)) { throw new RuntimeException('Could not rotate the account session.'); }
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        if ($user !== null) { $_SESSION += ['user_id' => $user['id'], 'session_version' => (int)$user['session_version'], 'issued_at' => time(), 'last_seen' => time()]; }
    }

    public static function currentUser(FMDatabase $db): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { return null; }
        $now = time();
        $absolute = (int)(self::$config['session']['lifetime'] ?? 28800);
        $idle = (int)(self::$config['session']['idle_timeout'] ?? 3600);
        if ($now - (int)($_SESSION['issued_at'] ?? 0) > $absolute || $now - (int)($_SESSION['last_seen'] ?? 0) > $idle) { self::rotateSession(); return null; }
        $user = $db->one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null || (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) { self::rotateSession(); return null; }
        $_SESSION['last_seen'] = $now;
        return $user;
    }

    public static function requireUser(FMDatabase $db): array { $user = self::currentUser($db); if ($user === null) { throw new FMError(401, 'authentication_required', 'Please sign in to continue.'); } return $user; }
    public static function requireAdmin(FMDatabase $db): array { $user = self::requireUser($db); if ($user['role'] !== 'admin') { throw new FMError(403, 'admin_required', 'This action requires a platform administrator.'); } return $user; }
    public static function verificationRequired(): bool
    {
        return (bool)(self::$config['mail']['enabled'] ?? false) && (bool)(self::$config['mail']['require_verification'] ?? true);
    }

    public static function requireVerified(array $user): void { if (self::verificationRequired() && empty($user['email_verified_at'])) { throw new FMError(403, 'email_verification_required', 'Verify your email before continuing.'); } }

    public static function rateLimit(FMDatabase $db, string $scope, int $limit, int $seconds): void
    {
        $now = time();
        $bucket = hash_hmac('sha256', $scope, (string)self::$config['app_key']);
        if ($db->driver === 'mysql') {
            $db->execute('INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = IF(reset_at <= ?, 1, hits + 1), reset_at = IF(reset_at <= ?, ?, reset_at)', [$bucket, $now + $seconds, $now, $now, $now + $seconds]);
        } else {
            $db->execute('INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, ?) ON CONFLICT(bucket) DO UPDATE SET hits = CASE WHEN rate_limits.reset_at <= ? THEN 1 ELSE rate_limits.hits + 1 END, reset_at = CASE WHEN rate_limits.reset_at <= ? THEN ? ELSE rate_limits.reset_at END', [$bucket, $now + $seconds, $now, $now, $now + $seconds]);
        }
        $row = $db->one('SELECT hits, reset_at FROM rate_limits WHERE bucket = ?', [$bucket]);
        if ($row !== null && (int)$row['hits'] > $limit) {
            header('Retry-After: ' . max(1, (int)$row['reset_at'] - $now));
            throw new FMError(429, 'rate_limited', 'Too many attempts. Please wait before trying again.');
        }
    }

    public static function audit(FMDatabase $db, ?string $userId, string $event, array $metadata = []): void
    {
        foreach (array_keys($metadata) as $key) { if (preg_match('/password|token|secret|cookie|authorization/i', (string)$key)) { unset($metadata[$key]); } }
        $db->execute('INSERT INTO audit_log (id,user_id,event,metadata,created_at) VALUES (?,?,?,?,?)', [$db->id(), $userId, substr($event, 0, 80), json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), time()]);
    }

    public static function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        if (self::isHttpsRequest()) { header('Strict-Transport-Security: max-age=31536000'); }
    }
}
