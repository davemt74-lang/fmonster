<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

/** First-run installer. It creates tables in the supplied database, never a database. */
final class FMInstaller
{
    private string $configPath;
    private string $lockPath;
    private array $test;
    private array $sessionConfig;

    public function __construct(string $privateDirectory, array $test = [])
    {
        $this->test = $test;
        $this->configPath = $test['config_path'] ?? fm_config_path();
        $this->lockPath = $test['lock_path'] ?? $privateDirectory . '/.install.lock';
        $url = $test['app_url'] ?? self::siteUrl();
        if ($test !== [] && !in_array(strtolower((string)parse_url($url, PHP_URL_HOST)), ['localhost', '127.0.0.1', '::1', 'terminal.local'], true)) { throw new RuntimeException('Installer test settings require a local URL.'); }
        if (($test === [] || str_starts_with($url, 'https://')) && !FMSecurity::isHttpsRequest()) { throw new FMError(426, 'https_required', 'Open this installer using HTTPS to protect your database and account details.'); }
        $this->sessionConfig = ['app_env' => $test === [] ? 'production' : 'test', 'app_url' => rtrim($url, '/'), 'app_key' => bin2hex(random_bytes(32)), 'session' => $test['session'] ?? ['lifetime' => 28800, 'idle_timeout' => 3600]];
    }

    public function startSession(): void { FMSecurity::configure($this->sessionConfig); FMSecurity::startSession(); }

    private static function siteUrl(): string
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/D', $host)) { throw new FMError(400, 'host_invalid', 'Open the installer at your website’s HTTPS address.'); }
        $host = preg_replace('/:443$/D', '', $host);
        $directory = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install.php')));
        $directory = $directory === '/' || $directory === '.' ? '' : '/' . implode('/', array_map('rawurlencode', array_filter(explode('/', $directory), static fn(string $part): bool => $part !== '' && $part !== '.' && $part !== '..')));
        return 'https://' . $host . $directory;
    }

    private function marker(): array
    {
        if (!is_file($this->lockPath)) { return []; }
        $decoded = json_decode((string)file_get_contents($this->lockPath), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function ownedPendingConfig(array $marker): ?array
    {
        if (($marker['state'] ?? '') !== 'pending' || !is_file($this->configPath)) { return null; }
        $hash = hash_file('sha256', $this->configPath);
        if (!is_string($hash) || !is_string($marker['config_hash'] ?? null) || !hash_equals($marker['config_hash'], $hash)) { return null; }
        $config = require $this->configPath;
        return is_array($config) ? $config : null;
    }

    public function alreadyInstalled(): bool
    {
        $marker = $this->marker();
        if (($marker['state'] ?? '') === 'complete') { return true; }
        if (!file_exists($this->configPath)) { return false; }
        $pending = $this->ownedPendingConfig($marker);
        if ($pending === null) { return true; }
        try { return (new FMDatabase($pending['database']))->one('SELECT value_text FROM app_meta WHERE key_name = ?', ['installed']) !== null; }
        catch (Throwable) { return true; } // Unknown database state must never permit config replacement.
    }

    private static function databaseInput(array $input): array
    {
        $host = FMAuth::text($input + ['db_host' => 'localhost'], 'db_host', 253);
        $name = FMAuth::text($input, 'db_name', 64);
        $user = FMAuth::text($input, 'db_user', 128);
        $password = $input['db_password'] ?? null;
        if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $host) || !preg_match('/^[A-Za-z0-9_-]+$/D', $name)) { throw new FMError(422, 'database_settings_invalid', 'Check your database host and database name.'); }
        if (!is_string($password) || $password === '' || strlen($password) > 4096 || str_contains($password, "\0")) { throw new FMError(422, 'database_password_required', 'Enter the password for your database user.'); }
        return ['driver' => 'mysql', 'host' => strtolower($host), 'port' => 3306, 'name' => $name, 'user' => $user, 'password' => $password];
    }

    private static function credentialHash(array $database, string $key): string { return hash_hmac('sha256', json_encode($database, JSON_THROW_ON_ERROR), $key); }

    private static function writeAll($handle, string $contents): void
    {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) { throw new FMError(503, 'configuration_write_failed', 'The server could not save the configuration. Check free disk space and folder write permission, then try again.'); }
            $offset += $written;
        }
        if (!fflush($handle)) { throw new FMError(503, 'configuration_write_failed', 'The server could not finish saving the configuration. Please try again.'); }
        if (function_exists('fsync')) { @fsync($handle); }
    }

    private static function writeMarker($handle, array $marker): void
    {
        rewind($handle);
        if (!ftruncate($handle, 0)) { throw new RuntimeException('Could not update installer state.'); }
        self::writeAll($handle, json_encode($marker, JSON_THROW_ON_ERROR));
    }

    private function publish(string $stage, string $contents): void
    {
        // Prefer an atomic, complete-file publication. Shared hosts may disable
        // hard links; exclusive create still guarantees no existing file is overwritten.
        if (function_exists('link') && @link($stage, $this->configPath)) { return; }
        $handle = @fopen($this->configPath, 'x+b');
        if ($handle === false) { throw new FMError(503, 'configuration_write_failed', 'The server could not safely create config.php. Check folder write permission and try again.'); }
        try {
            if (!@chmod($this->configPath, 0600)) { throw new RuntimeException('Could not protect configuration permissions.'); }
            self::writeAll($handle, $contents);
        } catch (Throwable $error) { @unlink($this->configPath); throw $error; }
        finally { fclose($handle); }
    }

    public function install(array $input): array
    {
        $databaseInput = self::databaseInput($input);
        FMAuth::text($input, 'name', 120);
        FMAuth::email($input);
        FMAuth::password($input);
        if (!is_string($input['confirm_password'] ?? null) || !hash_equals($input['password'], $input['confirm_password'])) { throw new FMError(422, 'password_mismatch', 'The account passwords must match.'); }
        $oldMask = umask(0077);
        $lock = @fopen($this->lockPath, 'c+b');
        umask($oldMask);
        if ($lock === false) { throw new FMError(503, 'configuration_write_failed', 'The server needs write permission for foodmonster-private to save your installation. Update the folder permission and try again.'); }
        @chmod($this->lockPath, 0600);
        $stage = null;
        $published = false;
        $committed = false;
        $locked = false;
        $configHash = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new FMError(409, 'installer_busy', 'Another installation is running. Please wait a moment, then reload.'); }
            $locked = true;
            $marker = $this->marker();
            if (($marker['state'] ?? '') === 'complete') { throw new FMError(409, 'already_installed', 'Food Monster is already installed.'); }
            if (file_exists($this->configPath)) {
                $pending = $this->ownedPendingConfig($marker);
                if ($pending === null) { throw new FMError(409, 'already_installed', 'Food Monster already has a configuration. It has not been changed.'); }
                if (!hash_equals((string)($marker['credentials_hash'] ?? ''), self::credentialHash($databaseInput, (string)$pending['app_key']))) { throw new FMError(403, 'database_credentials_invalid', 'Use the same database credentials to retry the interrupted installation.'); }
                $previousDb = new FMDatabase($pending['database']);
                if ($previousDb->one('SELECT value_text FROM app_meta WHERE key_name = ?', ['installed']) !== null) { self::writeMarker($lock, ['state' => 'complete']); throw new FMError(409, 'already_installed', 'Food Monster is already installed.'); }
                if (!@unlink($this->configPath)) { throw new FMError(503, 'configuration_write_failed', 'The interrupted configuration could not be recovered. Check folder write permission and try again.'); }
            }
            $database = $this->test['database'] ?? $databaseInput;
            $driver = (string)($database['driver'] ?? 'mysql');
            if (!class_exists('PDO') || !in_array($driver, PDO::getAvailableDrivers(), true)) { throw new FMError(503, 'database_driver_missing', 'Enable the PHP PDO MySQL extension in your hosting control panel, then try again.'); }
            try { $db = new FMDatabase($database); }
            catch (Throwable) { throw new FMError(422, 'database_connection_failed', 'Could not connect to that database. Check its name, username, password and host, then try again.'); }
            if ($db->installed()) { throw new FMError(409, 'database_already_installed', 'This database already contains a Food Monster installation. Use its existing website and account.'); }
            $domain = preg_replace('/^www\./', '', (string)parse_url($this->sessionConfig['app_url'], PHP_URL_HOST));
            $config = $this->sessionConfig + [
                'base_domain' => $this->test['base_domain'] ?? ($this->test !== [] ? 'foodmonster.test' : $domain),
                'database' => $database,
                'mail' => $this->test['mail'] ?? ['enabled' => false, 'transport' => 'mail', 'from' => 'accounts@' . $domain, 'from_name' => 'Food Monster'],
            ];
            $contents = "<?php\ndeclare(strict_types=1);\nif (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }\n\n// Created by the Food Monster installer. Keep this file private.\nreturn " . var_export($config, true) . ";\n";
            $configHash = hash('sha256', $contents);
            $stage = dirname($this->configPath) . '/.foodmonster-stage-' . bin2hex(random_bytes(16)) . '.php';
            $oldMask = umask(0077);
            $handle = @fopen($stage, 'x+b');
            umask($oldMask);
            if ($handle === false) { throw new FMError(503, 'configuration_write_failed', 'The server needs write permission for foodmonster-private to save config.php. Update the folder permission and try again.'); }
            try { if (!@chmod($stage, 0600)) { throw new RuntimeException('Could not protect configuration permissions.'); } self::writeAll($handle, $contents); }
            finally { fclose($handle); }
            self::writeMarker($lock, ['state' => 'pending', 'config_hash' => $configHash, 'credentials_hash' => self::credentialHash($databaseInput, $config['app_key'])]);
            $db->migrate(fm_schema_directory());
            FMSecurity::configure($config);
            $administrator = $db->transaction(function () use ($db, $input, $stage, $contents, &$published): array {
                try { $db->execute('INSERT INTO app_meta (key_name,value_text) VALUES (?,?)', ['installed', '1']); }
                catch (PDOException $error) {
                    if (in_array((string)$error->getCode(), ['23000', '23505'], true)) { throw new FMError(409, 'already_installed', 'This database is already installed.'); }
                    throw $error;
                }
                $existingUsers = $db->one('SELECT COUNT(*) AS total FROM users');
                if ((int)($existingUsers['total'] ?? 0) > 0) { throw new FMError(409, 'database_not_empty', 'This database already contains accounts. Choose an empty database or log in to the existing installation.'); }
                $owner = FMAuth::createAdministrator($db, $input);
                $db->execute('INSERT INTO app_meta (key_name,value_text) VALUES (?,?)', ['schema_version', '1']);
                $db->execute('INSERT INTO app_meta (key_name,value_text) VALUES (?,?)', ['installed_at', (string)time()]);
                $this->publish($stage, $contents);
                $published = true;
                return $owner;
            });
            $committed = true;
            // Config + the committed database lock already block a rerun if the
            // final file marker cannot be updated after a successful commit.
            try { self::writeMarker($lock, ['state' => 'complete']); } catch (Throwable) {}
            try { FMAuth::sendInitialVerification($db, $config, $administrator); } catch (Throwable) {}
            FMSecurity::rotateSession();
            return ['user' => FMAuth::publicUser($administrator)];
        } catch (Throwable $error) {
            if ($locked && !$committed) {
                $safeToRemove = true;
                if ($published) {
                    try {
                        if ($db->one('SELECT value_text FROM app_meta WHERE key_name = ?', ['installed']) !== null) {
                            $committed = true;
                            try { self::writeMarker($lock, ['state' => 'complete']); } catch (Throwable) {}
                            return [];
                        }
                    } catch (Throwable) { $safeToRemove = false; }
                    if ($safeToRemove && is_file($this->configPath) && hash_equals((string)$configHash, (string)hash_file('sha256', $this->configPath))) { @unlink($this->configPath); }
                }
                if (!file_exists($this->configPath) && ($this->marker()['state'] ?? '') !== 'complete') { try { self::writeMarker($lock, []); } catch (Throwable) {} }
            }
            throw $error;
        } finally {
            if ($stage !== null && is_file($stage)) { @unlink($stage); }
            if ($locked) { @flock($lock, LOCK_UN); }
            fclose($lock);
        }
    }
}
