<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMHostingSettings
{
    public static function summary(FMDatabase $db): ?array
    {
        if (!FMLicensingUpgrade::hostedReady($db)) {
            return null;
        }
        $row = $db->one('SELECT hostname, username, database_prefix, tested_at FROM fm_hosting_settings WHERE id = 1');
        return $row ? $row + ['token_configured' => true] : ['token_configured' => false];
    }

    public static function dispatch(string $action, array $input, FMDatabase $db, array $config): ?array
    {
        if (!in_array($action, ['admin.cpanel_save', 'admin.cpanel_test', 'admin.cpanel_preflight'], true)) {
            return null;
        }
        $actor = FMSecurity::requireAdmin($db);
        if (!FMLicensingUpgrade::hostedReady($db)) {
            throw new FMError(503, 'upgrade_required', 'Run the hosted setup database upgrade first.');
        }
        if ($config['app_env'] === 'production' && !FMSecurity::isHttpsRequest()) {
            throw new FMError(403, 'https_required', 'Hosting settings require HTTPS.');
        }
        FMSecurity::rateLimit($db, 'hosting_settings:' . $actor['id'], 20, 600);
        if ($action === 'admin.cpanel_save') {
            return self::save($db, $config, $input, $actor['id']);
        }
        if ($action === 'admin.cpanel_preflight') {
            // Release the browser session while the read-only remote checks run.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            return FMHostingPreflight::inspect($db, $config);
        }
        $row = self::connection($db, $config);
        (new FMCpanel($row, $config))->assertOwnsDomain((string)$config['base_domain']);
        $db->execute('UPDATE fm_hosting_settings SET tested_at = ? WHERE id = 1 AND encrypted_token = ? AND hostname = ? AND username = ? AND database_prefix = ?',
            [time(), $row['encrypted_token'], $row['hostname'], $row['username'], $row['database_prefix']]);
        $saved = self::summary($db);
        if (empty($saved['tested_at']) || $saved['hostname'] !== $row['hostname'] || $saved['username'] !== $row['username']
            || $saved['database_prefix'] !== $row['database_prefix']) {
            throw new FMError(409, 'hosting_settings_changed', 'The saved connection changed during the test. Test the current settings again.');
        }
        FMSecurity::audit($db, $actor['id'], 'hosting.connection_tested');
        return ['cpanel' => $saved];
    }

    public static function connection(FMDatabase $db, array $config): array
    {
        $row = $db->one('SELECT * FROM fm_hosting_settings WHERE id = 1');
        if (!$row) {
            throw new FMError(409, 'cpanel_unconfigured', 'Save the cPanel connection before testing or provisioning.');
        }
        self::requireSodium();
        $bytes = base64_decode($row['encrypted_token'], true);
        if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('The saved hosting credential is invalid.');
        }
        $nonce = substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $token = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key($config));
        if ($token === false) {
            throw new FMError(409, 'cpanel_credential_invalid', 'Save the cPanel token again after changing the Food Monster application key.');
        }
        return $row + ['token' => $token];
    }

    private static function save(FMDatabase $db, array $config, array $input, string $actor): array
    {
        self::requireSodium();
        $host = FMCpanel::hostname(FMAuth::text($input, 'hostname', 253), $config);
        $user = FMAuth::text($input, 'username', 16);
        $prefix = FMAuth::text($input, 'database_prefix', 16, false);
        $token = FMAuth::text($input, 'token', 256, false);
        if (!preg_match('/^[a-z][a-z0-9]{0,15}$/D', $user) || ($prefix !== '' && !preg_match('/^[a-z0-9]{1,16}$/D', $prefix))) {
            throw new FMError(422, 'invalid_cpanel_account', 'Enter the cPanel username and database prefix shown in cPanel.');
        }
        if ($token !== '' && !preg_match('/^[A-Za-z0-9._-]{16,256}$/D', $token)) {
            throw new FMError(422, 'invalid_cpanel_token', 'Enter a valid cPanel API token.');
        }

        return $db->transaction(static function () use ($db, $config, $actor, $host, $user, $prefix, $token): array {
            $sql = 'SELECT * FROM fm_hosting_settings WHERE id = 1' . ($db->driver === 'mysql' ? ' FOR UPDATE' : '');
            $old = $db->one($sql);
            if ($token === '' && (!$old || $old['hostname'] !== $host || $old['username'] !== $user)) {
                throw new FMError(422, 'cpanel_token_required', 'Provide a token for the selected cPanel server and account.');
            }
            if ($token === '') {
                $secret = $old['encrypted_token'];
            } else {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $secret = base64_encode($nonce . sodium_crypto_secretbox($token, $nonce, self::key($config)));
            }
            if ($old) {
                $db->execute('UPDATE fm_hosting_settings SET hostname = ?, username = ?, database_prefix = ?, encrypted_token = ?, tested_at = NULL, updated_at = ? WHERE id = 1',
                    [$host, $user, $prefix, $secret, time()]);
            } else {
                $db->execute('INSERT INTO fm_hosting_settings (id, hostname, username, database_prefix, encrypted_token, updated_at) VALUES (1, ?, ?, ?, ?, ?)',
                    [$host, $user, $prefix, $secret, time()]);
            }
            FMSecurity::audit($db, $actor, 'hosting.connection_saved');
            return ['cpanel' => self::summary($db)];
        });
    }

    private static function key(array $config): string
    {
        return hash_hkdf('sha256', (string)$config['app_key'], SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'foodmonster-cpanel-token-v1');
    }

    private static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new FMError(503, 'credential_encryption_required', 'Enable the PHP Sodium extension to store hosting credentials.');
        }
    }
}
