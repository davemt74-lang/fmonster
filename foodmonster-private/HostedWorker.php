<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMHostedWorker
{
    private const UPDATE_PRESERVE = ['config.php', '.env', '.user.ini', 'php.ini', 'storage', 'uploads',
        'gelato-private-storage', '.well-known', 'cgi-bin', '.htpasswd', 'error_log'];

    private readonly FMCpanel $cpanel;
    private readonly FMHostedOperations $operations;
    private readonly string $tenantRoot;
    private readonly string $privateRoot;
    private readonly string $home;
    private readonly string $prefix;
    private readonly string $fingerprint;

    public function __construct(private readonly FMDatabase $db, private readonly array $config)
    {
        if (PHP_SAPI !== 'cli' || !FMLicensingUpgrade::operationsReady($db)) {
            throw new RuntimeException('Run the hosted setup upgrade before using the CLI worker.');
        }
        $connection = FMHostingSettings::connection($db, $config);
        if (empty($connection['tested_at'])) {
            throw new RuntimeException('Verify the cPanel connection before provisioning.');
        }
        $this->cpanel = new FMCpanel($connection, $config);
        $this->operations = new FMHostedOperations($db);
        [$this->home, $this->tenantRoot, $this->privateRoot] = FMHostingPreflight::paths($config);
        $this->prefix = $connection['database_prefix'] === '' ? '' : $connection['database_prefix'] . '_';
        $this->fingerprint = hash('sha256', implode("\0", [$connection['hostname'], $connection['username'], $this->prefix,
            $this->home, $this->tenantRoot, $this->privateRoot, $config['base_domain'], (string)$config['app_key']]));
    }

    public function run(string $id, bool $scheduled = false): string
    {
        return $this->withJobLock($id, function (string $work) use ($id, $scheduled): string {
            $action = $this->operations->begin($id, $scheduled);
            if ($action === 'cancelled') {
                throw new FMError(409, 'setup_cancelled', 'This hosted setup was cancelled.');
            }
            if (in_array($action, ['ready', 'skipped', 'retry_wait', 'failed'], true)) {
                return $action;
            }
            $command = $action !== 'run';
            try {
                if ($command) {
                    return $this->cancelLocked($id, $work, $action === 'release');
                }
                $result = $this->provision($id, $work);
                return $result === 'awaiting_https' ? $this->operations->failure($id, 'https_pending') : $result;
            } catch (Throwable $error) {
                $state = $this->operations->failure($id, $error instanceof FMError ? $error->errorCode : 'worker_error', $command);
                if ($state === 'retry_wait') {
                    return $state;
                }
                throw $error;
            }
        });
    }

    public function runUpdate(string $attempt, bool $recover = false): string
    {
        $update = FMManagedUpdates::job($this->db, $attempt);
        return $this->withJobLock($update['installation_id'], function (string $work) use ($attempt, $recover): string {
            $update = FMManagedUpdates::job($this->db, $attempt);
            if (!in_array($update['phase'], ['queued', 'swapping', 'recovery'], true)) {
                return 'skipped';
            }
            if ($recover && !in_array($update['phase'], ['swapping', 'recovery'], true)) {
                throw new FMError(409, 'recovery_unavailable', 'This update has not entered deployment.');
            }
            if ($update['phase'] === 'recovery' && !$recover) {
                return 'skipped';
            }
            $site = $this->job($update['installation_id']);
            if ($site['state'] !== 'ready' || $site['hosting_mode'] !== 'cloud' || $site['released_at'] !== null
                || !hash_equals((string)$site['hosting_fingerprint'], $this->fingerprint)
                || !preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', (string)$site['subdomain'])
                || $site['domain'] !== $site['subdomain'] . '.' . $this->config['base_domain']) {
                $message = 'Restore the original managed hosting settings before processing this update.';
                if ($recover) {
                    throw new FMError(409, 'hosting_settings_changed', $message);
                }
                $phase = $update['phase'] === 'queued' ? 'failed' : 'recovery';
                FMManagedUpdates::finish($this->db, $update, $phase, $message);
                return $phase;
            }
            $target = $this->tenantRoot . '/' . $site['subdomain'];
            $dir = $work . '/update-' . $attempt;
            self::ensurePrivate($dir);
            $stage = $dir . '/stage';
            $backup = $dir . '/backup';
            $journal = $dir . '/preserved.json';
            $proof = hash_hmac('sha256', 'health:' . $update['installation_id'], (string)$this->config['app_key']);
            $healthy = fn (string $version): bool => $this->updateHealthy($site['domain'], $proof, $update['installation_id'] . "\n" . $version);
            if ($update['phase'] !== 'queued') {
                return $this->restoreUpdate($update, $target, $stage, $backup, $journal, $healthy);
            }

            try {
                FMLicensing::authorizeManagedWork($this->db, $this->config, $update, static fn() => null);
                $mapped = $this->cpanel->call('domain', ['domain' => $site['domain']])['documentroot'] ?? null;
                if ($mapped !== $target || self::localVersion($target) !== $update['from_version'] || !$healthy($update['from_version'])
                    || file_exists($backup) || is_link($backup)) {
                    throw new FMError(409, 'baseline_unverified', 'The current site, version, or document root could not be verified.');
                }
                $this->stageUpdate($update, $dir, $stage, $target);
                $data = json_encode(self::updateData($target), JSON_THROW_ON_ERROR);
                if (is_link($journal) || file_put_contents($journal, $data, LOCK_EX) !== strlen($data)) {
                    throw new RuntimeException('Could not record the site paths before deployment.');
                }
                FMLicensing::authorizeManagedWork($this->db, $this->config, $update, function () use ($attempt): void {
                    $this->db->execute("UPDATE fm_managed_updates SET phase = 'swapping', updated_at = ? WHERE attempt_id = ? AND phase = 'queued'", [time(), $attempt]);
                });
            } catch (PDOException $error) {
                // A lost database acknowledgement may have committed the swap marker.
                throw $error;
            } catch (Throwable $error) {
                if (!file_exists($backup) && !is_link($backup)) {
                    self::discardUpdateStage($stage);
                    @unlink($dir . '/release.zip');
                }
                $message = $error instanceof FMError ? $error->getMessage() : 'Food Monster could not stage or validate the release.';
                FMManagedUpdates::finish($this->db, $update, 'failed', $message);
                return 'failed';
            }
            try {
                self::moveDirectory($target, $backup);
                self::moveUpdateData($backup, $stage, self::expectedUpdateData($journal));
                if (!chmod($stage, 0755)) {
                    throw new RuntimeException('Could not set the public release directory permissions.');
                }
                self::moveDirectory($stage, $target);
                if (array_diff(self::expectedUpdateData($journal), self::updateData($target)) || !$healthy($update['version'])) {
                    throw new FMError(409, 'update_health_failed', 'The updated site did not pass its HTTPS health check.');
                }
            } catch (Throwable) {
                return $this->restoreUpdate($update, $target, $stage, $backup, $journal, $healthy);
            }
            try {
                FMLicensing::authorizeManagedWork($this->db, $this->config, $update,
                    fn() => FMManagedUpdates::finish($this->db, $update, 'completed', null));
            } catch (FMError) {
                return $this->restoreUpdate($update, $target, $stage, $backup, $journal, $healthy);
            }
            return 'completed';
        });
    }

    private function stageUpdate(array $update, string $dir, string $stage, string $target): void
    {
        [, , $source, $checksum] = FMLicensing::hostedPackage($this->db, $this->config, $update['release_id']);
        if (file_exists($stage) || is_link($stage)) {
            self::removeStage($stage);
        }
        self::ensurePrivate($stage);
        $archive = $dir . '/release.zip';
        if (is_link($archive) || !copy($source, $archive) || !hash_equals($checksum, (string)hash_file('sha256', $archive))) {
            throw new FMError(409, 'package_changed', 'The staged ZIP no longer matches the signed release.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new FMError(409, 'invalid_package', 'The staged ZIP is inconsistent.');
        }
        try {
            if ($zip->locateName('food-monster-hosted-health.php') === false) {
                throw new FMError(409, 'health_probe_missing', 'The release has no hosted health endpoint.');
            }
            $bytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $file = $zip->statIndex($index);
                $root = explode('/', strtolower((string)$file['name']), 2)[0];
                if (in_array($root, self::UPDATE_PRESERVE, true) || str_starts_with($root, '.fm-ready-')) {
                    throw new FMError(409, 'protected_update_path', 'The release contains a tenant or hosting-owned path.');
                }
                $bytes += (int)$file['size'];
            }
            if (disk_free_space($dir) < $bytes + 16777216 || !$zip->extractTo($stage)) {
                throw new FMError(409, 'staging_space_unavailable', 'The release could not be staged. Check free disk space and account quotas.');
            }
        } finally {
            $zip->close();
            @unlink($archive);
        }
        foreach (['database', 'migrations'] as $path) {
            if (self::migrationFiles($target . '/' . $path) !== self::migrationFiles($stage . '/' . $path)) {
                throw new FMError(409, 'migration_changes', 'Database files changed. This release must be deployed manually.');
            }
        }
    }

    private function restoreUpdate(array $update, string $target, string $stage, string $backup, string $journal, callable $healthy): string
    {
        try {
            $expected = self::expectedUpdateData($journal);
            if (file_exists($backup) || is_link($backup)) {
                if (self::localVersion($backup) !== $update['from_version']) {
                    throw new RuntimeException('The private backup could not be verified.');
                }
                if (file_exists($target) || is_link($target)) {
                    if (self::localVersion($target) !== $update['version']) {
                        throw new RuntimeException('The active site changed during rollback.');
                    }
                    self::moveDirectory($target, $stage);
                }
                self::moveUpdateData($stage, $backup, $expected);
                self::moveDirectory($backup, $target);
            }
            if (self::localVersion($target) !== $update['from_version'] || array_diff($expected, self::updateData($target)) || !$healthy($update['from_version'])) {
                throw new RuntimeException('Could not verify the previous site after recovery.');
            }
        } catch (Throwable) {
            FMManagedUpdates::finish($this->db, $update, 'recovery', 'The previous version could not be verified. A hosting operator must recover this update.');
            return 'recovery';
        }
        FMManagedUpdates::finish($this->db, $update, 'rolled_back', 'The update was interrupted or could not be verified. The previous version was restored.');
        self::discardUpdateStage($stage);
        return 'rolled_back';
    }

    private static function discardUpdateStage(string $stage): void
    {
        if (is_dir($stage) && !is_link($stage)) {
            try {
                self::removeStage($stage);
            } catch (RuntimeException) {
                error_log('Food Monster could not remove a private update staging directory.');
            }
        }
    }

    private static function expectedUpdateData(string $journal): array
    {
        if (is_link($journal) || !is_file($journal)) {
            throw new RuntimeException('The preserved paths could not be verified.');
        }
        $paths = json_decode((string)file_get_contents($journal), true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($paths) || !in_array('config.php', $paths, true)
            || array_filter($paths, static fn ($path): bool => !in_array($path, self::UPDATE_PRESERVE, true))) {
            throw new RuntimeException('The preserved paths are invalid.');
        }
        return $paths;
    }

    private static function updateData(string $root): array
    {
        clearstatcache();
        $paths = [];
        foreach (self::UPDATE_PRESERVE as $name) {
            $path = $root . '/' . $name;
            if (is_link($path) || (file_exists($path) && !is_file($path) && !is_dir($path))) {
                throw new RuntimeException('A tenant-owned path needs operator review.');
            }
            if (file_exists($path)) {
                $paths[] = $name;
            }
        }
        if (!is_file($root . '/config.php')) {
            throw new RuntimeException('The managed site configuration is missing.');
        }
        return $paths;
    }

    private static function moveDirectory(string $from, string $to): void
    {
        clearstatcache();
        if (is_link($from) || !is_dir($from) || file_exists($to) || is_link($to) || !@rename($from, $to)) {
            throw new RuntimeException('Could not move the application directory.');
        }
    }

    private static function moveUpdateData(string $from, string $to, array $paths): void
    {
        if (is_link($from) || is_link($to) || !is_dir($to)) {
            throw new RuntimeException('The temporary site directory needs operator review.');
        }
        clearstatcache();
        foreach ($paths as $name) {
            $path = $from . '/' . $name;
            if (file_exists($path) || is_link($path)) {
                $dest = $to . '/' . $name;
                if (is_link($path) || is_link($dest) || file_exists($dest)
                    || (!is_file($path) && !is_dir($path)) || !@rename($path, $dest)) {
                    throw new RuntimeException('Could not preserve a tenant-owned path.');
                }
            }
        }
    }

    private static function localVersion(string $path): ?string
    {
        clearstatcache();
        $file = $path . '/GELATO_VERSION.txt';
        return !is_link($path) && !is_link($file) && is_file($file) ? trim((string)file_get_contents($file, false, null, 0, 100)) : null;
    }

    private function updateHealthy(string $domain, string $proof, string $expected): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                sleep(1);
            }
            if ($this->httpsReady($domain, $proof, $expected)) {
                return true;
            }
        }
        return false;
    }

    private static function migrationFiles(string $directory): array
    {
        if (!file_exists($directory) && !is_link($directory)) {
            return [];
        }
        if (!is_dir($directory) || is_link($directory)) {
            throw new FMError(409, 'migration_changes', 'The database directory could not be checked for a file-only update.');
        }
        $files = [];
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || !$entry->isFile() || ($hash = hash_file('sha256', $path)) === false) {
                throw new FMError(409, 'migration_changes', 'The database files could not be checked for a file-only update.');
            }
            $files[substr($path, strlen($directory))] = $hash;
        }
        ksort($files);
        return $files;
    }

    public function cancel(string $id, bool $releaseAfterCleanup = false): string
    {
        return $this->withJobLock($id, fn (string $work): string => $this->cancelLocked($id, $work, $releaseAfterCleanup));
    }

    private function cancelLocked(string $id, string $work, bool $releaseAfterCleanup): string
    {
        $job = $this->job($id);
        if ($job['state'] === 'ready') {
            throw new FMError(409, 'setup_live', 'A completed installation requires a separate decommissioning process.');
        }
        if ($job['released_at'] !== null) {
            return 'cancelled_and_released';
        }
        if ($releaseAfterCleanup) {
            if ($job['state'] !== 'cancelled' || $job['hosting_fingerprint'] === null
                || !hash_equals($job['hosting_fingerprint'], $this->fingerprint)) {
                throw new FMError(409, 'cleanup_unverified', 'Cancel this setup and restore its original hosting settings before releasing it.');
            }
            $target = $this->tenantRoot . '/' . $job['subdomain'];
            $database = $this->prefix . 'fm' . substr($id, 0, 12);
            if (file_exists($target) || is_link($target) || $this->domainExists($job['domain'])
                || $this->cpanelExists('databases', 'database', $database) || $this->cpanelExists('users', 'user', $database)) {
                throw new FMError(409, 'cleanup_pending', 'The domain, document root, database and database user must be removed before releasing the reservation.');
            }
        }
        $release = $releaseAfterCleanup || $job['stage'] === 'queued';
        if ($release) {
            foreach (['app', 'gelato-private'] as $directory) {
                if (is_dir($work . '/' . $directory) || is_link($work . '/' . $directory)) {
                    self::removeStage($work . '/' . $directory);
                }
            }
            foreach (['release.zip', 'verified-stage'] as $file) {
                $path = $work . '/' . $file;
                if ((is_file($path) || is_link($path)) && !unlink($path)) {
                    throw new RuntimeException('Could not clear the private setup files.');
                }
            }
        }
        $this->db->transaction(function () use ($id, $job, $release): void {
            $this->lockOwner($job['user_id']);
            $now = time();
            $this->db->execute("UPDATE fm_hosted_setups SET state = 'cancelled', owner_profile = '{}', password_hash = NULL, updated_at = ? WHERE installation_id = ?",
                [$now, $id]);
            if ($release) {
                $this->db->execute('DELETE FROM fm_domain_claims WHERE installation_id = ?', [$id]);
                $this->db->execute("UPDATE fm_installations SET status = 'released', released_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $id]);
            }
            $this->operations->progress($id, $job['stage'], $release ? 'released' : 'cancelled');
            FMSecurity::audit($this->db, $job['user_id'], 'installation.hosted_setup_cancelled', ['installation_id' => $id, 'released' => $release]);
        });
        return $release ? 'cancelled_and_released' : 'cancelled_cleanup_required';
    }

    private function withJobLock(string $id, callable $callback): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('Provide an installation ID.');
        }
        $work = $this->privateRoot . '/' . $id;
        self::ensurePrivate($work);
        $lockPath = $work . '/worker.lock';
        $lock = is_link($lockPath) ? false : fopen($lockPath, 'c+b');
        if (!$lock) {
            throw new RuntimeException('Cannot acquire the installation worker lock.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new FMError(409, 'worker_busy', 'This installation is already being provisioned.');
            }
            return $callback($work);
        } finally {
            fclose($lock);
        }
    }

    private function provision(string $id, string $work): string
    {
        $job = $this->job($id);
        if ($job['state'] === 'ready') {
            return 'ready';
        }
        if ($job['state'] === 'cancelled') {
            throw new FMError(409, 'setup_cancelled', 'This hosted setup was cancelled.');
        }
        if ($job['hosting_fingerprint'] !== null && !hash_equals($job['hosting_fingerprint'], $this->fingerprint)) {
            throw new FMError(409, 'hosting_settings_changed', 'Restore the original hosting settings before retrying this installation.');
        }
        $this->eligible($job);
        $domain = $job['domain'];
        $expectedDomain = $job['subdomain'] . '.' . $this->config['base_domain'];
        if ($domain !== $expectedDomain || !preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $job['subdomain'])) {
            throw new RuntimeException('The installation is outside the configured Cloud parent domain.');
        }
        $target = $this->tenantRoot . '/' . $job['subdomain'];
        $database = $this->prefix . 'fm' . substr($id, 0, 12);
        $dbUser = $database;
        $dbPassword = 'Fm!' . hash_hmac('sha256', 'db:' . $id, (string)$this->config['app_key']);
        $key = 'fm1_' . hash_hmac('sha256', 'license:' . $id, (string)$this->config['app_key']);
        $instance = substr(hash_hmac('sha256', 'instance:' . $id, (string)$this->config['app_key']), 0, 32);
        $proof = hash_hmac('sha256', 'health:' . $id, (string)$this->config['app_key']);
        $proofFile = '.fm-ready-' . $id;
        $stage = $work . '/app';
        $stamp = $work . '/verified-stage';

        if ($job['stage'] === 'queued') {
            $this->cpanel->assertOwnsDomain($this->config['base_domain']);
            FMHostingPreflight::parentRoot($this->cpanel, $this->config['base_domain'], $this->tenantRoot, $this->privateRoot);
            if (file_exists($target) || is_link($target) || $this->domainExists($domain)
                || $this->cpanelExists('databases', 'database', $database) || $this->cpanelExists('users', 'user', $dbUser)) {
                throw new FMError(409, 'hosting_conflict', 'The hostname, site directory, or database already exists. An operator must review it.');
            }
            [$release, , $path, $checksum] = FMLicensing::hostedPackage($this->db, $this->config, null);
            $this->prepareFiles($stage, $work, $path, $checksum, $id, $domain, $database, $dbUser, $dbPassword, $job, $proof);
            $verified = $this->stageProof($id, $checksum);
            if (is_link($stamp) || file_put_contents($stamp, $verified, LOCK_EX) !== strlen($verified) || !chmod($stamp, 0600)) {
                throw new RuntimeException('Could not record the verified staging files.');
            }
            $this->db->transaction(function () use ($id, $release, $job): void {
                $this->lockOwner($job['user_id']);
                $this->eligible($this->job($id));
                $this->db->execute("UPDATE fm_hosted_setups SET hosting_fingerprint = ?, release_id = ?, state = 'working', stage = 'host', updated_at = ? WHERE installation_id = ?",
                    [$this->fingerprint, $release, time(), $id]);
                $this->db->execute("UPDATE fm_installations SET status = 'provisioning', deployment_started_at = ?, updated_at = ? WHERE id = ?", [time(), time(), $id]);
                $this->operations->progress($id, 'host');
            });
            $job = $this->job($id);
        }

        $release = $this->pinnedRelease($job);
        $version = $release['version'];
        $verified = $this->stageProof($id, $release['checksum']);
        if (!is_file($stamp) || is_link($stamp) || !hash_equals($verified, (string)file_get_contents($stamp, false, null, 0, 65))) {
            throw new FMError(409, 'untrusted_staging', 'The staging files predate signature verification. Cancel and clean up this setup before requeueing.');
        }
        if ($job['stage'] === 'host') {
            if (!$this->domainExists($domain)) {
                $this->cpanel->call('add_subdomain', ['domain' => $job['subdomain'], 'rootdomain' => $this->config['base_domain'],
                    'dir' => substr($target, strlen($this->home))]);
            }
            $domainData = $this->cpanel->call('domain', ['domain' => $domain]);
            if (($domainData['documentroot'] ?? null) !== $target || is_link($target)) {
                throw new FMError(409, 'document_root_mismatch', 'cPanel did not assign the expected document root.');
            }
            if (!$this->cpanelExists('databases', 'database', $database)) {
                $this->cpanel->call('add_database', ['name' => $database]);
            }
            if (!$this->cpanelExists('users', 'user', $dbUser)) {
                $this->cpanel->call('add_user', ['name' => $dbUser, 'password' => $dbPassword]);
            }
            $this->cpanel->call('grant', ['user' => $dbUser, 'database' => $database, 'privileges' => 'ALL PRIVILEGES']);
            $this->progress($id, 'working', 'seed');
            $job['stage'] = 'seed';
        }

        if ($job['stage'] === 'seed') {
            if (!$this->hasPublishedFiles($target, $proofFile, $verified)) {
                if (!is_dir($stage) || is_link($stage)) {
                    throw new RuntimeException('The deployment directories need operator review.');
                }
                $this->checkDocumentRoot($target);
                $profile = json_decode($job['owner_profile'], true, 8, JSON_THROW_ON_ERROR);
                $profile['password_hash'] = $job['password_hash'];
                $this->seedGelato($stage, $profile, ['installation_id' => $id, 'instance_id' => $instance, 'key' => $key], $version);
                if (file_put_contents($stage . '/' . $proofFile, $verified, LOCK_EX) !== strlen($verified)
                    || !chmod($stage . '/' . $proofFile, 0600)) {
                    throw new RuntimeException('The staged site could not be published to its dedicated document root.');
                }
                $this->publishFiles($stage, $target);
            }
            $this->progress($id, 'working', 'verify');
            $job['stage'] = 'verify';
        }

        if ($job['stage'] !== 'verify' || !$this->hasPublishedFiles($target, $proofFile, $verified)) {
            throw new RuntimeException('The published site needs operator review.');
        }
        if (!$this->httpsReady($domain, $proof, $id . "\n" . $version)) {
            return 'awaiting_https';
        }

        $this->db->transaction(function () use ($id, $job, $domain, $version, $instance, $key): void {
            $this->lockOwner($job['user_id']);
            $current = $this->job($id);
            $this->eligible($current);
            $this->pinnedRelease($current, true);
            $now = time();
            $this->db->execute("UPDATE fm_installations SET status = 'active', verified_at = ?, activated_at = ?, updated_at = ? WHERE id = ?",
                [$now, $now, $now, $id]);
            FMLicensing::activateHosted($this->db, $id, $domain, $version, $instance, $key);
            $this->db->execute("UPDATE fm_hosted_setups SET state = 'ready', stage = 'ready', owner_profile = '{}', password_hash = NULL, updated_at = ? WHERE installation_id = ?",
                [$now, $id]);
            $this->operations->progress($id, 'ready', 'ready');
            FMSecurity::audit($this->db, $job['user_id'], 'installation.hosted_setup_completed', ['installation_id' => $id, 'version' => $version]);
        });
        @unlink($target . '/' . $proofFile);
        @unlink($stamp);
        return 'ready';
    }

    private function prepareFiles(string $stage, string $work, string $path, string $checksum, string $id, string $domain,
        string $database, string $dbUser, string $dbPassword, array $job, string $proof): void
    {
        if (is_dir($stage) || is_link($stage)) {
            self::removeStage($stage);
        }
        self::ensurePrivate($stage);
        $archive = $work . '/release.zip';
        if (is_link($archive) || !copy($path, $archive) || !chmod($archive, 0600) || !hash_equals($checksum, (string)hash_file('sha256', $archive))) {
            throw new RuntimeException('The staged release ZIP checksum could not be verified.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Could not open the staged Gelato ZIP.');
        }
        try {
            if ($zip->locateName('scripts/food-monster-provision.php') === false || $zip->locateName('database/install.sql') === false
                || $zip->locateName('food-monster-hosted-health.php') === false) {
                throw new RuntimeException('Publish a Gelato release that includes the hosted installer.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $root = explode('/', strtolower((string)$zip->getNameIndex($index)), 2)[0];
                if (in_array($root, ['cgi-bin', '.well-known'], true) || str_starts_with($root, '.fm-ready-')) {
                    throw new RuntimeException('The hosted ZIP contains a hosting-owned path.');
                }
            }
            if (!$zip->extractTo($stage)) {
                throw new RuntimeException('Could not extract the signed Gelato release.');
            }
        } finally {
            $zip->close();
            @unlink($archive);
        }

        $profile = json_decode($job['owner_profile'], true, 8, JSON_THROW_ON_ERROR);
        $runtime = $work . '/gelato-private';
        self::ensurePrivate($runtime);
        $host = $this->config['hosting']['database_host'] ?? 'localhost';
        $port = (int)($this->config['hosting']['database_port'] ?? 3306);
        if (!is_string($host) || !preg_match('/^[A-Za-z0-9._-]+$/D', $host) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid hosting database server configuration.');
        }
        $gelato = [
            'app' => ['name' => 'Gelato', 'url' => 'https://' . $domain, 'timezone' => $profile['timezone'], 'debug' => false],
            'database' => ['host' => $host, 'port' => $port, 'name' => $database, 'username' => $dbUser, 'password' => $dbPassword, 'charset' => 'utf8mb4'],
            'security' => ['cookie_secure' => true],
            'storage' => ['private_root' => $runtime],
            'food_monster_hosted' => ['installation_id' => $id, 'health_hash' => hash('sha256', $proof)],
        ];
        // While staging, the Gelato CLI treats this sibling runtime directory as private.
        $file = $stage . '/config.php';
        $content = "<?php\nreturn " . var_export($gelato, true) . ";\n";
        if (file_put_contents($file, $content, LOCK_EX) !== strlen($content) || !chmod($file, 0600)) {
            throw new RuntimeException('Could not create the private Gelato configuration.');
        }
    }

    private function seedGelato(string $stage, array $profile, array $connection, string $version): void
    {
        $binary = ($this->config['app_env'] ?? '') === 'test' ? ($this->config['hosting']['test_php'] ?? PHP_BINARY) : PHP_BINARY;
        $command = [$binary, '-d', 'display_errors=0', $stage . '/scripts/food-monster-provision.php'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes, $stage);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not launch the private Gelato installer.');
        }
        $payload = json_encode(['profile' => $profile, 'connection' => $connection], JSON_THROW_ON_ERROR);
        $sent = fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $reply = stream_get_contents($pipes[1], 4096);
        fclose($pipes[1]);
        $code = proc_close($process);
        $data = is_string($reply) ? json_decode($reply, true) : null;
        if ($code !== 0 || $sent !== strlen($payload) || ($data['ok'] ?? null) !== true || ($data['version'] ?? null) !== $version) {
            throw new FMError(409, 'seed_failed', 'Gelato could not initialize the target schema, owner, or installation credential.');
        }
    }

    private function httpsReady(string $domain, string $proof, string $expected): bool
    {
        $check = ($this->config['app_env'] ?? '') === 'test' ? ($this->config['hosting']['test_https'] ?? null) : null;
        if (is_callable($check)) {
            return $check($domain, $proof, $expected) === true;
        }
        $curl = curl_init('https://' . $domain . '/food-monster-hosted-health.php');
        if (!$curl) {
            return false;
        }
        $body = '';
        try {
            curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['X-Food-Monster-Health: ' . $proof],
                CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 128) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                }]);
            return curl_exec($curl) !== false && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 && hash_equals($expected, $body);
        } finally {
            curl_close($curl);
        }
    }

    private function job(string $id): array
    {
        $row = $this->db->one('SELECT h.*, i.user_id, i.domain, i.subdomain, i.hosting_mode, i.released_at, i.created_at AS registration_created_at, s.status AS subscription_status,
            s.expires_at FROM fm_hosted_setups h JOIN fm_installations i ON i.id = h.installation_id
            JOIN fm_subscriptions s ON s.id = i.subscription_id WHERE h.installation_id = ?', [$id]);
        if ($row === null) {
            throw new FMError(404, 'setup_missing', 'The installation has no hosted setup request.');
        }
        return $row;
    }

    private function eligible(array $job): void
    {
        if ($job['released_at'] !== null || $job['hosting_mode'] !== 'cloud' || $job['subscription_status'] !== 'active'
            || (int)$job['expires_at'] <= time() || !is_string($job['password_hash'])) {
            throw new FMError(409, 'setup_ineligible', 'The setup requires an active Cloud subscription and submitted owner credentials.');
        }
        FMSubdomains::assertCurrent($this->db, ['id' => $job['installation_id'], 'hosting_mode' => 'cloud', 'created_at' => $job['registration_created_at']]);
    }

    private function pinnedRelease(array $job, bool $lock = false): array
    {
        $sql = "SELECT version, checksum FROM fm_releases WHERE id = ? AND status = 'published' AND channel = 'stable'";
        $release = $this->db->one($sql . ($lock && $this->db->driver === 'mysql' ? ' FOR UPDATE' : ''), [$job['release_id']]);
        if (!$release) {
            throw new FMError(409, 'release_unavailable', 'The pinned stable release was withdrawn.');
        }
        FMLicensing::assertHostedSignature($this->config, $release['version'], (string)$release['checksum']);
        return $release;
    }

    private function stageProof(string $id, string $checksum): string
    {
        return hash_hmac('sha256', 'hosted-stage:' . $id . ':' . $checksum, (string)$this->config['app_key']);
    }

    private function hasPublishedFiles(string $target, string $file, string $proof): bool
    {
        $marker = $target . '/' . $file;
        return !is_link($target) && !is_link($marker) && is_file($marker)
            && hash_equals($proof, (string)file_get_contents($marker, false, null, 0, 65));
    }

    private function checkDocumentRoot(string $target): array
    {
        if (is_link($target) || (file_exists($target) && !is_dir($target))) {
            throw new RuntimeException('The deployment directories need operator review.');
        }
        if (!is_dir($target)) {
            return [];
        }
        $entries = scandir($target);
        if ($entries === false) {
            throw new RuntimeException('Could not inspect the cPanel document root.');
        }
        $entries = array_values(array_diff($entries, ['.', '..']));
        foreach ($entries as $entry) {
            $path = $target . '/' . $entry;
            if (!in_array($entry, ['.well-known', 'cgi-bin'], true) || is_link($path) || !is_dir($path)
                || ($entry === 'cgi-bin' && scandir($path) !== ['.', '..'])) {
                throw new FMError(409, 'document_root_conflict', 'The cPanel document root contains an unexpected path. Review it before retrying.');
            }
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($items as $item) {
                if ($item->isLink() || (!$item->isFile() && !$item->isDir())) {
                    throw new FMError(409, 'document_root_conflict', 'The cPanel document root contains an unsupported entry.');
                }
            }
        }
        return $entries;
    }

    private function publishFiles(string $stage, string $target): void
    {
        // Retain provider directories and atomically replace the empty root.
        // A retry after an interruption sees moved directories in staging.
        $moved = [];
        try {
            foreach ($this->checkDocumentRoot($target) as $entry) {
                if (file_exists($stage . '/' . $entry) || is_link($stage . '/' . $entry)
                    || !rename($target . '/' . $entry, $stage . '/' . $entry)) {
                    throw new RuntimeException('Could not preserve the cPanel directory.');
                }
                $moved[] = $entry;
            }
            if (!chmod($stage, 0755) || !rename($stage, $target)) {
                throw new RuntimeException('Could not publish the prepared Gelato site.');
            }
        } catch (Throwable $error) {
            foreach (array_reverse($moved) as $entry) {
                if (!file_exists($target . '/' . $entry) && !is_link($target . '/' . $entry)) {
                    @rename($stage . '/' . $entry, $target . '/' . $entry);
                }
            }
            throw $error;
        }
    }

    private function lockOwner(string $id): void
    {
        if ($this->db->driver === 'mysql') {
            $this->db->one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id]);
        }
    }

    private function progress(string $id, string $state, string $stage): void
    {
        $this->db->transaction(function () use ($id, $state, $stage): void {
            $this->db->execute('UPDATE fm_hosted_setups SET state = ?, stage = ?, updated_at = ? WHERE installation_id = ?', [$state, $stage, time(), $id]);
            $this->operations->progress($id, $stage);
        });
    }

    private function domainExists(string $domain): bool
    {
        $data = $this->cpanel->call('domains');
        $all = array_merge([(string)($data['main_domain'] ?? '')], (array)($data['addon_domains'] ?? []),
            (array)($data['parked_domains'] ?? []), (array)($data['sub_domains'] ?? []));
        return in_array($domain, $all, true);
    }

    private function cpanelExists(string $operation, string $field, string $name): bool
    {
        foreach ($this->cpanel->call($operation) as $row) {
            if (is_array($row) && ($row[$field] ?? null) === $name) {
                return true;
            }
        }
        return false;
    }

    private static function ensurePrivate(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700)) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('A private provisioning directory could not be secured.');
        }
    }

    private static function removeStage(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            throw new RuntimeException('The temporary site directory needs operator review.');
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $removed = $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$removed) {
                throw new RuntimeException('Could not reset the incomplete private staging directory.');
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Could not reset the incomplete private staging directory.');
        }
    }
}
