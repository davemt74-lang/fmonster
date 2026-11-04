<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMAuth
{
    private const GENERIC_SIGNUP = 'Check your email for the next step. If you already have an account, sign in or request a password reset.';
    private const GENERIC_RESET = 'If an account matches that email, check your inbox and spam folder for a reset link. If nothing arrives, try again later or contact the operator.';

    public static function publicUser(array $user): array
    {
        return ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'email_verified' => !empty($user['email_verified_at']), 'role' => $user['role']];
    }

    public static function bootstrap(FMDatabase $db, array $config): array
    {
        $user = FMSecurity::currentUser($db);
        return ['csrf' => FMSecurity::csrf(), 'user' => $user === null ? null : self::publicUser($user), 'configured' => true, 'mail_available' => FMMail::available($config), 'verification_required' => FMSecurity::verificationRequired()];
    }

    public static function text(array $input, string $field, int $max, bool $required = true): string
    {
        $value = $input[$field] ?? '';
        if (!is_string($value) || !preg_match('//u', $value)) { throw new FMError(422, 'validation_failed', 'Please check the highlighted fields.', [$field => 'Enter valid text.']); }
        $value = trim($value);
        $count = preg_match_all('/./us', $value);
        if (($required && $value === '') || $count === false || $count > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) { throw new FMError(422, 'validation_failed', 'Please check the highlighted fields.', [$field => 'Enter between ' . ($required ? '1' : '0') . ' and ' . $max . ' characters.']); }
        return $value;
    }

    public static function email(array $input): string
    {
        $email = strtolower(self::text($input, 'email', 254));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new FMError(422, 'validation_failed', 'Enter a valid email address.', ['email' => 'Enter a valid email address.']); }
        return $email;
    }

    public static function password(array $input, string $field = 'password'): string
    {
        $password = $input[$field] ?? null;
        $count = is_string($password) && preg_match('//u', $password) ? preg_match_all('/./us', $password) : false;
        if (!is_string($password) || $count === false || $count < 12 || strlen($password) > 1024 || str_contains($password, "\0")) { throw new FMError(422, 'validation_failed', 'Use a password of at least 12 characters.', [$field => 'Use at least 12 characters and no more than 1,024 bytes.']); }
        if (!self::hasArgon2id() && strlen($password) > 72) { throw new FMError(422, 'validation_failed', 'This server supports passwords up to 72 bytes.', [$field => 'Use at most 72 bytes. Some characters use more than one byte.']); }
        return $password;
    }

    private static function hasArgon2id(): bool { return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true); }
    public static function hashPassword(string $password): string
    {
        return self::hasArgon2id() ? password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1]) : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    private static function verifyPassword(string $password, string $hash): bool
    {
        if (strlen($password) > 1024 || str_contains($password, "\0") || (str_starts_with($hash, '$2') && strlen($password) > 72)) { return false; }
        return password_verify($password, $hash);
    }

    private static function requireMail(array $config): void
    {
        if (!FMMail::available($config)) { throw new FMError(503, 'mail_unavailable', 'Account email is not configured yet. Please contact the Food Monster operator.'); }
    }

    private static function token(FMDatabase $db, string $userId, string $type): string
    {
        $raw = bin2hex(random_bytes(32));
        $now = time();
        // Keep the same lock order as password changes, then invalidate the old
        // token and insert its replacement under one transaction.
        if ($db->driver === 'mysql') { $db->one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]); }
        $db->execute('UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [$now, $userId, $type]);
        $db->execute('INSERT INTO auth_tokens (token_hash,user_id,type,expires_at,used_at,created_at) VALUES (?,?,?,?,NULL,?)', [hash('sha256', $raw), $userId, $type, $now + ($type === 'verify' ? 86400 : 3600), $now]);
        return $raw;
    }

    private static function delivery(FMDatabase $db, array $config, array $user, string $kind, string $token): bool
    {
        try { $sent = FMMail::send($config, $user['email'], $kind, $token); }
        catch (Throwable) { $sent = false; }
        FMSecurity::audit($db, $user['id'], $sent ? 'email.accepted' : 'email.failed', ['kind' => $kind]);
        return $sent;
    }

    private static function tokenInput(array $input): string
    {
        $raw = $input['token'] ?? '';
        if (!is_string($raw) || !preg_match('/^[a-f0-9]{64}$/D', $raw)) { throw new FMError(422, 'token_invalid', 'This link is invalid or has expired. Please request a new one.'); }
        return hash('sha256', $raw);
    }

    private static function consume(FMDatabase $db, string $hash, string $type): array
    {
        $row = $db->one('SELECT user_id FROM auth_tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > ?', [$hash, $type, time()]);
        if ($row === null) { throw new FMError(422, 'token_invalid', 'This link is invalid or has expired. Please request a new one.'); }
        $user = $db->one('SELECT * FROM users WHERE id = ?' . ($db->driver === 'mysql' ? ' FOR UPDATE' : ''), [$row['user_id']]);
        if ($user === null) { throw new FMError(422, 'token_invalid', 'This link is invalid or has expired. Please request a new one.'); }
        if ($db->execute('UPDATE auth_tokens SET used_at = ? WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > ?', [time(), $hash, $type, time()]) !== 1) { throw new FMError(422, 'token_invalid', 'This link is invalid or has expired. Please request a new one.'); }
        return $user;
    }

    public static function createAdministrator(FMDatabase $db, array $input): array
    {
        $name = self::text($input, 'name', 120);
        $email = self::email($input);
        $hash = self::hashPassword(self::password($input));
        $user = ['id' => $db->id(), 'name' => $name, 'email' => $email, 'password_hash' => $hash, 'email_verified_at' => null, 'role' => 'admin', 'session_version' => 1, 'created_at' => time(), 'updated_at' => time()];
        $db->execute('INSERT INTO users (id,name,email,password_hash,email_verified_at,role,session_version,created_at,updated_at) VALUES (?,?,?,?,NULL,?,1,?,?)', [$user['id'], $name, $email, $hash, 'admin', $user['created_at'], $user['updated_at']]);
        $db->execute('INSERT INTO business_profiles (user_id,business_name,created_at,updated_at) VALUES (?,?,?,?)', [$user['id'], 'Food Monster', time(), time()]);
        FMSecurity::audit($db, $user['id'], 'setup.administrator_created');
        return $user;
    }

    public static function sendInitialVerification(FMDatabase $db, array $config, array $user): bool
    {
        if (!FMMail::available($config)) { return false; }
        $raw = $db->transaction(fn() => self::token($db, $user['id'], 'verify'));
        return self::delivery($db, $config, $user, 'verify', $raw);
    }

    public static function dispatch(string $action, array $input, FMDatabase $db, array $config): ?array
    {
        $ip = FMSecurity::clientIp();
        switch ($action) {
            case 'bootstrap': return self::bootstrap($db, $config);
            case 'auth.signup':
                FMSecurity::rateLimit($db, 'signup:ip:' . $ip, 10, 3600);
                $name = self::text($input, 'name', 120);
                $business = self::text($input, 'business_name', 160);
                $email = self::email($input);
                $password = self::password($input);
                FMSecurity::rateLimit($db, 'signup:email:' . $email, 3, 3600);
                $mailAvailable = FMMail::available($config);
                if (FMSecurity::verificationRequired()) { self::requireMail($config); }
                // Hash even duplicate addresses so the response does not expose account existence through a cheap timing difference.
                $passwordHash = self::hashPassword($password);
                $user = null;
                $token = null;
                try {
                    $db->transaction(function () use ($db, $name, $business, $email, $passwordHash, $mailAvailable, &$user, &$token): void {
                        if ($db->one('SELECT id FROM users WHERE email = ?', [$email]) !== null) { return; }
                        $user = ['id' => $db->id(), 'name' => $name, 'email' => $email, 'role' => 'customer', 'email_verified_at' => null, 'session_version' => 1];
                        $now = time();
                        $db->execute('INSERT INTO users (id,name,email,password_hash,email_verified_at,role,session_version,created_at,updated_at) VALUES (?,?,?,?,NULL,?,1,?,?)', [$user['id'], $name, $email, $passwordHash, 'customer', $now, $now]);
                        $db->execute('INSERT INTO business_profiles (user_id,business_name,created_at,updated_at) VALUES (?,?,?,?)', [$user['id'], $business, $now, $now]);
                        if ($mailAvailable) { $token = self::token($db, $user['id'], 'verify'); }
                        FMSecurity::audit($db, $user['id'], 'auth.signup');
                    });
                } catch (PDOException $error) {
                    if (!in_array((string)$error->getCode(), ['23000', '23505'], true) || $db->one('SELECT id FROM users WHERE email = ?', [$email]) === null) { throw $error; }
                    $user = null;
                }
                if ($user !== null && $token !== null) { self::delivery($db, $config, $user, 'verify', $token); }
                return ['message' => $mailAvailable ? self::GENERIC_SIGNUP : 'You can sign in with your email and password.'];

            case 'auth.login':
                FMSecurity::rateLimit($db, 'login:ip:' . $ip, 30, 900);
                $email = self::email($input);
                FMSecurity::rateLimit($db, 'login:email:' . $email, 12, 900);
                $password = $input['password'] ?? '';
                if (!is_string($password)) { $password = ''; }
                $user = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
                // This valid bcrypt format still performs its configured work when no matching account exists.
                $dummy = '$2y$12$14X6Fjbj.BIjqXqsvMi4muC4ZAca0X4fMetIh9frAKMI62nxXgBV6';
                $valid = self::verifyPassword($password, $user['password_hash'] ?? $dummy);
                if ($user === null || !$valid) { FMSecurity::audit($db, null, 'auth.login_failed'); throw new FMError(401, 'credentials_invalid', 'The email or password is incorrect.'); }
                $algorithm = self::hasArgon2id() ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
                $options = self::hasArgon2id() ? ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1] : ['cost' => 12];
                if (password_needs_rehash($user['password_hash'], $algorithm, $options)) { $db->execute('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ? AND password_hash = ?', [self::hashPassword($password), time(), $user['id'], $user['password_hash']]); }
                FMSecurity::rotateSession($user);
                FMSecurity::audit($db, $user['id'], 'auth.login');
                return ['user' => self::publicUser($user), 'csrf' => FMSecurity::csrf()];

            case 'auth.logout':
                $user = FMSecurity::currentUser($db);
                if ($user !== null) { FMSecurity::audit($db, $user['id'], 'auth.logout'); }
                FMSecurity::rotateSession();
                return ['message' => 'You have been signed out.', 'csrf' => FMSecurity::csrf()];

            case 'auth.logout_all':
                $user = FMSecurity::requireUser($db);
                $db->execute('UPDATE users SET session_version = session_version + 1, updated_at = ? WHERE id = ?', [time(), $user['id']]);
                FMSecurity::audit($db, $user['id'], 'auth.logout_all');
                FMSecurity::rotateSession();
                return ['message' => 'All account sessions have been signed out.', 'csrf' => FMSecurity::csrf()];

            case 'auth.forgot':
                FMSecurity::rateLimit($db, 'forgot:ip:' . $ip, 10, 3600);
                $email = self::email($input);
                FMSecurity::rateLimit($db, 'forgot:email:' . $email, 3, 3600);
                self::requireMail($config);
                $user = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
                if ($user !== null) {
                    $token = $db->transaction(fn() => self::token($db, $user['id'], 'reset'));
                    self::delivery($db, $config, $user, 'reset', $token);
                    FMSecurity::audit($db, $user['id'], 'auth.reset_requested');
                }
                return ['message' => self::GENERIC_RESET];

            case 'auth.reset':
                FMSecurity::rateLimit($db, 'reset:ip:' . $ip, 15, 3600);
                $tokenHash = self::tokenInput($input);
                $passwordHash = self::hashPassword(self::password($input));
                $user = $db->transaction(function () use ($db, $tokenHash, $passwordHash): array {
                    $user = self::consume($db, $tokenHash, 'reset');
                    $db->execute('UPDATE users SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?', [$passwordHash, time(), $user['id']]);
                    $db->execute('UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [time(), $user['id'], 'reset']);
                    FMSecurity::audit($db, $user['id'], 'auth.password_reset');
                    return $user;
                });
                if (($_SESSION['user_id'] ?? null) === $user['id']) { FMSecurity::rotateSession(); }
                return ['message' => 'Your password has been reset. Sign in with your new password.', 'csrf' => FMSecurity::csrf()];

            case 'auth.verify':
                FMSecurity::rateLimit($db, 'verify:ip:' . $ip, 20, 3600);
                $hash = self::tokenInput($input);
                $db->transaction(function () use ($db, $hash): void {
                    $user = self::consume($db, $hash, 'verify');
                    $db->execute('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?', [time(), time(), $user['id']]);
                    $db->execute('UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [time(), $user['id'], 'verify']);
                    FMSecurity::audit($db, $user['id'], 'auth.email_verified');
                });
                return ['message' => 'Your email is verified. You can now choose a package and register your business.'];

            case 'auth.resend':
                $user = FMSecurity::requireUser($db);
                if (!empty($user['email_verified_at'])) { return ['message' => 'Your email is already verified.']; }
                FMSecurity::rateLimit($db, 'resend:ip:' . $ip, 10, 3600);
                FMSecurity::rateLimit($db, 'resend:user:' . $user['id'], 3, 900);
                self::requireMail($config);
                $token = $db->transaction(fn() => self::token($db, $user['id'], 'verify'));
                if (!self::delivery($db, $config, $user, 'verify', $token)) { throw new FMError(503, 'mail_unavailable', 'The verification email could not be delivered to the mail server. Please try again later.'); }
                return ['message' => 'A verification link has been sent to your email address.'];

            case 'auth.change_password':
                $user = FMSecurity::requireUser($db);
                FMSecurity::rateLimit($db, 'change_password:user:' . $user['id'], 6, 900);
                $current = $input['current_password'] ?? '';
                if (!is_string($current) || !self::verifyPassword($current, $user['password_hash'])) { throw new FMError(422, 'password_incorrect', 'Your current password is incorrect.', ['current_password' => 'Enter your current password.']); }
                $hash = self::hashPassword(self::password($input));
                $user = $db->transaction(function () use ($db, $user, $hash): array {
                    $changed = $db->execute('UPDATE users SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ? AND password_hash = ?', [$hash, time(), $user['id'], $user['password_hash']]);
                    if ($changed !== 1) { throw new FMError(409, 'account_changed', 'Your account changed in another session. Sign in and try again.'); }
                    $db->execute('UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [time(), $user['id'], 'reset']);
                    FMSecurity::audit($db, $user['id'], 'auth.password_changed');
                    return $db->one('SELECT * FROM users WHERE id = ?', [$user['id']]);
                });
                FMSecurity::rotateSession($user);
                return ['message' => 'Your password has been changed. Other sessions have been signed out.', 'user' => self::publicUser($user), 'csrf' => FMSecurity::csrf()];

            case 'account.update':
                $user = FMSecurity::requireUser($db);
                FMSecurity::rateLimit($db, 'profile:user:' . $user['id'], 20, 3600);
                $name = self::text($input, 'name', 120);
                $business = self::text($input, 'business_name', 160);
                $db->transaction(function () use ($db, $user, $name, $business): void {
                    $db->execute('UPDATE users SET name = ?, updated_at = ? WHERE id = ?', [$name, time(), $user['id']]);
                    $db->execute('UPDATE business_profiles SET business_name = ?, updated_at = ? WHERE user_id = ?', [$business, time(), $user['id']]);
                    FMSecurity::audit($db, $user['id'], 'account.updated');
                });
                $user['name'] = $name;
                return ['message' => 'Your account details have been saved.', 'user' => self::publicUser($user), 'business' => ['business_name' => $business]];
        }
        return null;
    }
}
