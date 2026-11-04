<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMLicensing
{
    public const CHANNELS = ['stable', 'beta', 'development'];
    private const PREFERENCES = ['off', 'stable', 'beta'];

    public static function dispatch(string $action, array $input, FMDatabase $db, array $config): ?array
    {
        return match ($action) {
            'admin.license_manage' => self::manageLicense($db, $input, FMSecurity::requireAdmin($db), true),
            'licenses.key' => self::manageLicense($db, ['operation' => 'rotate'] + $input, FMSecurity::requireUser($db), false),
            'licenses.apply_release' => self::approveManagedUpdate($db, $config, $input, FMSecurity::requireUser($db)),
            'admin.release_analytics' => FMManagedUpdates::analytics($db),
            'licenses.preference' => self::preference($db, $input, FMSecurity::requireUser($db)),
            'admin.release_create' => self::createRelease($db, $input, FMSecurity::requireAdmin($db)),
            'admin.release_status' => self::changeRelease($db, $config, $input, FMSecurity::requireAdmin($db)),
            default => null,
        };
    }

    public static function decorate(FMDatabase $db, array $installations, array $config): array
    {
        if (!FMLicensingUpgrade::ready($db)) {
            return $installations;
        }
        $releases = self::published($db);
        foreach ($installations as &$item) {
            $row = self::record($db, $item['id']);
            $item['licensing'] = $row === null ? null : self::snapshot($db, $row, $releases);
            $candidate = $item['licensing']['available_release'] ?? null;
            if ($candidate !== null && !empty($item['hosted_setup']) && $item['hosted_setup']['state'] === 'ready'
                && $item['licensing']['managed_update'] === null && FMLicensingUpgrade::updatesReady($db)) {
                $item['licensing']['managed_update_available'] = self::managedRelease($db, $config, $candidate);
            }
        }
        return $installations;
    }

    public static function registry(FMDatabase $db): array
    {
        if (!FMLicensingUpgrade::ready($db)) {
            return [];
        }
        $query = FMLicensingUpgrade::releaseIntakeReady($db)
            ? 'SELECT r.*, b.source_commit, b.public_key_sha256 FROM fm_releases r LEFT JOIN fm_signed_releases b ON b.release_id = r.id'
            : 'SELECT r.* FROM fm_releases r';
        return array_map(self::releaseData(...), $db->query($query . ' ORDER BY r.created_at DESC, r.id DESC'));
    }

    private static function ready(FMDatabase $db): void
    {
        if (!FMLicensingUpgrade::ready($db)) {
            throw new FMError(503, 'upgrade_required', 'An administrator must run the Food Monster database upgrade.');
        }
    }

    private static function id(array $input, string $field): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) {
            throw new FMError(422, 'invalid_identifier', 'A valid record identifier is required.');
        }
        return $value;
    }

    public static function validVersion(mixed $version): bool
    {
        return is_string($version) && strlen($version) <= 64
            && preg_match('/^(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})(?:-(?:dev|alpha|beta|rc)\.(?:0|[1-9][0-9]{0,8}))?$/D', $version) === 1;
    }

    private static function choice(array $input, string $field, array $choices): string
    {
        $value = $input[$field] ?? null;
        if (!in_array($value, $choices, true)) {
            throw new FMError(422, 'invalid_option', 'Choose a supported ' . str_replace('_', ' ', $field) . '.');
        }
        return $value;
    }

    private static function installation(FMDatabase $db, string $id, ?array $actor = null): array
    {
        $row = $db->one('SELECT * FROM fm_installations WHERE id = ?', [$id]);
        if (!$row || ($actor !== null && $actor['role'] !== 'admin' && $row['user_id'] !== $actor['id'])) {
            throw new FMError(404, 'installation_not_found', 'This installation does not exist.');
        }
        return $row;
    }

    private static function lockOwner(FMDatabase $db, string $owner): void
    {
        // Commerce takes the same lock before changing registrations or subscriptions.
        if ($db->driver === 'mysql') {
            $db->one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$owner]);
        }
    }

    private static function record(FMDatabase $db, string $installation): ?array
    {
        return $db->one(
            'SELECT l.*, i.user_id, i.subscription_id, i.domain, i.business_name, i.hosting_mode,
                    i.status AS installation_status, i.verified_at, i.released_at,
                    s.package_name, s.status AS subscription_status, s.expires_at AS subscription_expires_at,
                    s.max_installations
             FROM fm_licenses l JOIN fm_installations i ON i.id = l.installation_id
             JOIN fm_subscriptions s ON s.id = i.subscription_id WHERE l.installation_id = ?',
            [$installation]
        );
    }

    private static function effectiveExpiry(array $row): int
    {
        $subscription = (int)$row['subscription_expires_at'];
        return $row['expires_at'] === null ? $subscription : min($subscription, (int)$row['expires_at']);
    }

    private static function licenseState(array $row, int $now): string
    {
        if ($row['state'] === 'revoked' || $row['released_at'] !== null) {
            return 'revoked';
        }
        if ($row['state'] === 'suspended' || $row['subscription_status'] === 'suspended' || $row['installation_status'] === 'suspended') {
            return 'suspended';
        }
        if ($row['subscription_status'] === 'active' && self::effectiveExpiry($row) <= $now) {
            return 'expired';
        }
        if ($row['state'] !== 'active' || $row['subscription_status'] !== 'active') {
            return 'pending';
        }
        return 'active';
    }

    private static function deploymentReady(array $row): bool
    {
        return $row['installation_status'] === 'active' && $row['verified_at'] !== null && $row['released_at'] === null;
    }

    private static function manageLicense(FMDatabase $db, array $input, array $actor, bool $admin): array
    {
        self::ready($db);
        FMSecurity::requireVerified($actor);
        FMSecurity::rateLimit($db, 'license_manage:' . $actor['id'], 40, 300);
        $installation = self::id($input, 'installation_id');
        $owner = self::installation($db, $installation, $actor)['user_id'];
        $operation = self::choice($input, 'operation', ['issue', 'rotate', 'activate', 'deactivate', 'suspend', 'revoke', 'renew', 'policy']);

        return $db->transaction(static function () use ($db, $input, $actor, $admin, $installation, $owner, $operation): array {
            self::lockOwner($db, $owner);
            $registration = self::installation($db, $installation, $actor);
            if ($registration['user_id'] !== $owner) {
                throw new FMError(409, 'owner_changed', 'Refresh the installation after its transfer.');
            }
            if ($registration['released_at'] !== null) {
                throw new FMError(409, 'installation_released', 'This registration has been released.');
            }
            $row = self::record($db, $installation);
            $key = null;
            $now = time();
            $hosted = FMLicensingUpgrade::hostedReady($db)
                ? $db->one('SELECT state FROM fm_hosted_setups WHERE installation_id = ?', [$installation])
                : null;

            if ($operation === 'issue') {
                FMSubdomains::assertNoTransfer($db, $registration);
                if ($hosted !== null) {
                    throw new FMError(409, 'setup_managed', 'Hosted provisioning creates this installation’s license when it is ready.');
                }
                if ($row !== null) {
                    throw new FMError(409, 'license_exists', 'This installation already has a license.');
                }
                $key = 'fm1_' . bin2hex(random_bytes(32));
                $db->execute('INSERT INTO fm_licenses (id, installation_id, credential_hash, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$db->id(), $installation, hash('sha256', $key), 'active', $now, $now]);
            } else {
                if ($row === null || $row['state'] === 'revoked') {
                    throw new FMError(409, 'license_unavailable', 'There is no changeable license for this installation.');
                }
                if ($operation === 'rotate') {
                    if (FMManagedUpdates::active($db, $installation) !== null) {
                        throw new FMError(409, 'update_in_progress', 'Wait for the hosted update to finish before rotating the key.');
                    }
                    if ($hosted !== null && $hosted['state'] !== 'ready') {
                        throw new FMError(409, 'setup_managed', 'Wait for managed provisioning before rotating the key.');
                    }
                    if (!$admin && (self::licenseState($row, $now) !== 'active' || !self::deploymentReady($row))) {
                        throw new FMError(409, 'activation_unavailable', 'An active license and verified, active installation are required to generate a key.');
                    }
                    $key = 'fm1_' . bin2hex(random_bytes(32));
                    $db->execute("UPDATE fm_licenses SET credential_hash = ?, instance_id = NULL, activated_at = NULL, health = NULL, updated_at = ? WHERE id = ?",
                        [hash('sha256', $key), $now, $row['id']]);
                    $db->execute("UPDATE fm_update_attempts SET state = 'failed', error_summary = 'Installation key was rotated.', finished_at = ? WHERE license_id = ? AND state = 'started'",
                        [$now, $row['id']]);
                } elseif ($operation === 'renew') {
                    $expiry = $input['expires_at'] ?? null;
                    if ($expiry !== null && (!is_int($expiry) || $expiry <= $now || $expiry > $now + 315576000)) {
                        throw new FMError(422, 'invalid_expiry', 'Choose a future expiry, within ten years, or follow the subscription expiry.');
                    }
                    $db->execute('UPDATE fm_licenses SET expires_at = ?, updated_at = ? WHERE id = ?', [$expiry, $now, $row['id']]);
                } elseif ($operation === 'policy') {
                    $channel = self::choice($input, 'channel', self::CHANNELS);
                    $enabled = $input['updates_enabled'] ?? null;
                    if (!is_bool($enabled)) {
                        throw new FMError(422, 'invalid_entitlement', 'Choose whether updates are included.');
                    }
                    $preference = $enabled && $channel === $row['auto_update'] ? $row['auto_update'] : 'off';
                    $db->execute('UPDATE fm_licenses SET channel = ?, updates_enabled = ?, auto_update = ?, updated_at = ? WHERE id = ?',
                        [$channel, $enabled, $preference, $now, $row['id']]);
                } else {
                    $state = ['activate' => 'active', 'deactivate' => 'pending', 'suspend' => 'suspended', 'revoke' => 'revoked'][$operation];
                    $db->execute('UPDATE fm_licenses SET state = ?, updated_at = ? WHERE id = ?', [$state, $now, $row['id']]);
                }
            }
            $row = self::record($db, $installation);
            FMSecurity::audit($db, $actor['id'], 'license.' . $operation, ['license_id' => $row['id'], 'installation_id' => $installation]);
            return ['licensing' => self::snapshot($db, $row, self::published($db))] + ($key === null ? [] : ['activation_key' => $key]);
        });
    }

    private static function preference(FMDatabase $db, array $input, array $actor): array
    {
        self::ready($db);
        FMSecurity::requireVerified($actor);
        $installation = self::id($input, 'installation_id');
        $preference = self::choice($input, 'auto_update', self::PREFERENCES);
        $owner = self::installation($db, $installation, $actor)['user_id'];
        return $db->transaction(static function () use ($db, $actor, $installation, $preference, $owner): array {
            self::lockOwner($db, $owner);
            $row = self::record($db, $installation);
            if ($row === null || $row['released_at'] !== null || $row['state'] === 'revoked') {
                throw new FMError(409, 'license_unavailable', 'This installation has no changeable license.');
            }
            if ($preference !== 'off' && ($preference !== $row['channel'] || !$row['updates_enabled'])) {
                throw new FMError(409, 'channel_unavailable', 'The license must include updates on that channel.');
            }
            $db->execute('UPDATE fm_licenses SET auto_update = ?, updated_at = ? WHERE id = ?', [$preference, time(), $row['id']]);
            FMSecurity::audit($db, $actor['id'], 'updates.preference_changed', ['license_id' => $row['id'], 'preference' => $preference]);
            return ['auto_update' => $preference];
        });
    }

    public static function uploadRelease(FMDatabase $db, array $config, array $input, array $file, array $admin): array
    {
        self::ready($db);
        FMSecurity::rateLimit($db, 'release_upload:' . $admin['id'], 12, 3600);
        $version = $input['version'] ?? null;
        if (!self::validVersion($version)) {
            throw new FMError(422, 'invalid_version', 'Enter a valid Gelato release version.');
        }
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $bytes = $file['size'] ?? 0;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (is_int($bytes) && $bytes > 536870912)) {
            throw new FMError(413, 'upload_too_large', 'The ZIP exceeds the upload limit. Stage a larger supported ZIP in the private release folder.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_int($bytes) || $bytes < 22 || !is_string($file['tmp_name'] ?? null)) {
            throw new FMError(422, 'upload_incomplete', 'Choose a nonempty Gelato ZIP and let the upload finish.');
        }
        // Use a generated name to keep uploaded content independent of user-supplied paths.
        $name = 'gelato-' . $db->id() . '.zip';
        $path = self::packageDirectory($config) . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new FMError(503, 'upload_store_failed', 'The ZIP could not be saved in the private release folder.');
        }
        $committed = false;
        try {
            if (!chmod($path, 0600)) {
                throw new FMError(503, 'upload_permissions', 'The release ZIP could not be secured.');
            }
            [$checksum, $size] = self::inspectPackage($config, $name, $version);
            $data = self::createRelease($db, [
                'package_name' => $name,
                'migration_required' => ($input['migration_required'] ?? '') === 'on',
                'automatic_compatible' => ($input['automatic_compatible'] ?? '') === 'on',
            ] + $input, $admin, $checksum, $size);
            $committed = true;
            return $data;
        } finally {
            if (!$committed && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function uploadHostedBundle(FMDatabase $db, array $config, array $input, array $file, array $admin): array
    {
        FMSecurity::rateLimit($db, 'release_upload:' . $admin['id'], 12, 3600);
        $bytes = $file['size'] ?? 0;
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $bytes > 537919488) {
            throw new FMError(413, 'upload_too_large', 'The signed artifact exceeds the upload limit.');
        }
        $source = $file['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_int($bytes) || $bytes < 22 || !is_string($source) || !is_uploaded_file($source)) {
            throw new FMError(422, 'upload_incomplete', 'Choose a signed Gelato artifact ZIP and let the upload finish.');
        }
        return self::importHostedBundle($db, $config, $input, $source, $admin);
    }

    public static function importHostedBundle(FMDatabase $db, array $config, array $input, string $source, array $admin): array
    {
        if (!FMLicensingUpgrade::releaseIntakeReady($db)) {
            throw new FMError(503, 'upgrade_required', 'Run the signed release intake database upgrade.');
        }
        if (!class_exists('ZipArchive')) {
            throw new FMError(503, 'zip_unavailable', 'Enable the PHP ZIP extension before importing a signed release.');
        }
        $name = 'gelato-' . $db->id() . '.zip';
        $path = self::packageDirectory($config) . '/' . $name;
        $previousMask = umask(0077);
        try {
            $output = @fopen($path, 'xb');
        } finally {
            umask($previousMask);
        }
        if (!$output) {
            throw new FMError(503, 'upload_store_failed', 'The inner ZIP could not be saved in the private release folder.');
        }
        $committed = false;
        try {
            try {
                $bundle = FMHostedReleaseBundle::unpack($config, $source, $output);
            } finally {
                fclose($output);
            }
            [$checksum, $bytes] = self::inspectPackage($config, $name, $bundle['version']);
            if (!hash_equals($bundle['sha256'], $checksum)) {
                throw new FMError(409, 'hosted_bundle_checksum', 'The inner ZIP differs from the signed checksum.');
            }
            FMHostedReleaseBundle::assertIdentity($path, $bundle['source_commit']);
            $result = self::createRelease($db, [
                'version' => $bundle['version'], 'channel' => 'stable', 'notes' => $bundle['notes'], 'package_name' => $name,
                'migration_required' => ($input['migration_required'] ?? '') === 'on',
                'automatic_compatible' => ($input['automatic_compatible'] ?? '') === 'on',
            ] + $input, $admin, $checksum, $bytes, $bundle);
            $committed = true;
            return $result;
        } finally {
            if (!$committed && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private static function createRelease(FMDatabase $db, array $input, array $admin, ?string $checksum = null, ?int $bytes = null, ?array $bundle = null): array
    {
        self::ready($db);
        $version = $input['version'] ?? null;
        $minimum = ($input['minimum_version'] ?? '') === '' ? null : $input['minimum_version'];
        $channel = self::choice($input, 'channel', self::CHANNELS);
        if (!self::validVersion($version) || ($channel === 'stable' && str_contains($version, '-'))
            || ($minimum !== null && (!self::validVersion($minimum) || version_compare($minimum, $version, '>=')))) {
            throw new FMError(422, 'invalid_version', 'Enter a numeric release version and an earlier minimum version if needed. Stable releases require a final version.');
        }
        $name = $input['package_name'] ?? '';
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,170}\.zip$/D', $name)) {
            throw new FMError(422, 'invalid_package', 'Enter the ZIP filename in the private release directory.');
        }
        $notes = self::releaseText($input, 'notes', 10000, true);
        $rollback = self::releaseText($input, 'rollback_notes', 5000, false);
        $migration = $input['migration_required'] ?? null;
        $automatic = $input['automatic_compatible'] ?? null;
        if (!is_bool($migration) || !is_bool($automatic) || ($migration && $automatic)) {
            throw new FMError(422, 'invalid_update_policy', 'Releases requiring database migrations must be manual.');
        }
        $id = $db->id();
        try {
            $db->transaction(static function () use ($db, $id, $version, $channel, $notes, $name, $minimum, $migration, $automatic, $rollback, $admin, $checksum, $bytes, $bundle): void {
                $db->execute("INSERT INTO fm_releases (id, version, channel, status, notes, package_name, checksum, package_bytes, minimum_version, migration_required, automatic_compatible, rollback_notes, created_at) VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$id, $version, $channel, $notes, $name, $checksum, $bytes, $minimum, $migration, $automatic, $rollback, time()]);
                if ($bundle !== null) {
                    $db->execute('INSERT INTO fm_signed_releases (release_id, signature, public_key_sha256, source_commit) VALUES (?,?,?,?)',
                        [$id, $bundle['signature'], $bundle['public_key_sha256'], $bundle['source_commit']]);
                }
                FMSecurity::audit($db, $admin['id'], 'release.drafted', ['release_id' => $id, 'version' => $version, 'channel' => $channel]);
            });
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new FMError(409, 'release_exists', 'This version already exists on that channel.');
            }
            throw $error;
        }
        return ['release_id' => $id];
    }

    private static function releaseText(array $input, string $field, int $max, bool $required): string
    {
        $text = $input[$field] ?? '';
        if (!is_string($text)) {
            throw new FMError(422, 'invalid_notes', 'Enter valid release notes.');
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if (($required && $text === '') || !preg_match('//u', $text) || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $text)
            || preg_match_all('/./us', $text) > $max) {
            throw new FMError(422, 'invalid_notes', 'The release notes are empty, too long, or contain invalid characters.');
        }
        return $text;
    }

    private static function changeRelease(FMDatabase $db, array $config, array $input, array $admin): array
    {
        self::ready($db);
        $id = self::id($input, 'release_id');
        $target = self::choice($input, 'status', ['published', 'withdrawn']);
        $row = $db->one('SELECT * FROM fm_releases WHERE id = ?', [$id]);
        if (!$row) {
            throw new FMError(404, 'release_not_found', 'This release does not exist.');
        }
        $checksum = $row['checksum'];
        $bytes = $row['package_bytes'];
        $published = $row['published_at'];
        if ($target === 'published' && $row['status'] === 'draft') {
            [$checksum, $bytes] = self::inspectPackage($config, $row['package_name'], $row['version']);
            if ($row['checksum'] !== null && (!hash_equals($row['checksum'], $checksum) || (int)$row['package_bytes'] !== $bytes)) {
                throw new FMError(409, 'package_changed', 'The staged ZIP has changed since upload.');
            }
            if (self::storedSignature($db, $id) !== null) {
                self::assertReleaseSignature($db, $config, $row);
            }
            $published = time();
        }

        return $db->transaction(static function () use ($db, $admin, $id, $row, $target, $checksum, $bytes, $published): array {
            if ($row['status'] === $target) {
                return ['release' => self::releaseData($row)];
            }
            if ($row['status'] === 'withdrawn' || ($target === 'published' && $row['status'] !== 'draft')) {
                throw new FMError(409, 'release_transition', 'This release cannot change to the requested status.');
            }
            if ($db->execute('UPDATE fm_releases SET status = ?, checksum = ?, package_bytes = ?, published_at = ? WHERE id = ? AND status = ?',
                [$target, $checksum, $bytes, $published, $id, $row['status']]) !== 1) {
                throw new FMError(409, 'release_changed', 'The release changed. Refresh the registry and try again.');
            }
            if ($target === 'withdrawn') {
                $db->execute("UPDATE fm_update_attempts SET state = 'failed', error_summary = 'The release was withdrawn.', finished_at = ?
                    WHERE release_id = ? AND state = 'started' AND mode <> 'managed'", [time(), $id]);
            }
            FMSecurity::audit($db, $admin['id'], 'release.' . $target, ['release_id' => $id]);
            return ['release' => self::releaseData($db->one('SELECT * FROM fm_releases WHERE id = ?', [$id]))];
        });
    }

    private static function published(FMDatabase $db): array
    {
        $rows = $db->query("SELECT * FROM fm_releases WHERE status = 'published'");
        usort($rows, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
        return $rows;
    }

    private static function releaseData(array $row): array
    {
        return [
            'id' => $row['id'], 'version' => $row['version'], 'channel' => $row['channel'],
            'status' => $row['status'], 'notes' => $row['notes'], 'checksum' => $row['checksum'],
            'package_bytes' => $row['package_bytes'] === null ? null : (int)$row['package_bytes'],
            'minimum_version' => $row['minimum_version'], 'migration_required' => (bool)$row['migration_required'],
            'automatic_compatible' => (bool)$row['automatic_compatible'], 'rollback_notes' => $row['rollback_notes'],
            'source_commit' => $row['source_commit'] ?? null, 'public_key_sha256' => $row['public_key_sha256'] ?? null,
            'published_at' => $row['published_at'] === null ? null : (int)$row['published_at'],
        ];
    }

    private static function snapshot(FMDatabase $db, array $row, array $releases): array
    {
        $now = time();
        $version = $row['installed_version'];
        $latest = null;
        $candidate = null;
        $current = null;
        foreach ($releases as $release) {
            if ($release['channel'] !== $row['channel']) {
                continue;
            }
            $latest ??= $release;
            if ($version === $release['version']) {
                $current = $release;
            }
            if ($candidate === null && $version !== null && version_compare($version, $release['version'], '<')
                && ($release['minimum_version'] === null || version_compare($version, $release['minimum_version'], '>='))) {
                $candidate = $release;
            }
        }
        $state = self::licenseState($row, $now);
        $bound = $row['instance_id'] !== null;
        $reason = match (true) {
            $state !== 'active' => 'license_' . $state,
            !self::deploymentReady($row) => 'installation_inactive',
            !(bool)$row['updates_enabled'] => 'updates_excluded',
            !$bound => 'activation_required',
            $version === null => 'version_unknown',
            $latest === null => 'no_release',
            $candidate === null && version_compare($version, $latest['version'], '<') => 'minimum_version',
            $candidate === null => 'up_to_date',
            default => 'available',
        };
        $attempt = $row['latest_attempt_id'] === null ? null : $db->one(
            'SELECT a.id, a.mode, a.state, a.error_summary, a.started_at, a.finished_at, r.version, a.release_id
            FROM fm_update_attempts a JOIN fm_releases r ON r.id = a.release_id
            WHERE a.id = ? AND a.license_id = ?', [$row['latest_attempt_id'], $row['id']]
        );
        $activity = $db->one("SELECT MAX(CASE WHEN state = 'completed' THEN finished_at END) AS last_success,
            MAX(CASE WHEN state = 'failed' THEN finished_at END) AS last_failure
            FROM fm_update_attempts WHERE license_id = ?", [$row['id']]);
        $autoBlocked = $candidate !== null && $db->one("SELECT id FROM fm_update_attempts WHERE license_id = ? AND release_id = ?
            AND (state = 'started' OR (mode = 'automatic' AND state = 'failed')) LIMIT 1", [$row['id'], $candidate['id']]) !== null;
        $managed = $row['hosting_mode'] === 'cloud' ? FMManagedUpdates::active($db, $row['installation_id']) : null;
        if ($managed !== null) {
            $reason = 'managed_update_in_progress';
            if (($attempt['id'] ?? null) === $managed['attempt_id'] && $attempt['state'] === 'started') {
                // Existing Gelato builds show manual report controls for a started attempt.
                $attempt['state'] = $managed['phase'] === 'queued' ? 'queued' : 'deploying';
            }
        }
        $allowed = $reason === 'available';
        return [
            'license_id' => $row['id'], 'installation_id' => $row['installation_id'], 'license_state' => $state,
            'configured_license_state' => $row['state'], 'license_issued_at' => (int)$row['created_at'],
            'license_expiry_override' => $row['expires_at'] === null ? null : (int)$row['expires_at'],
            'subscription_id' => $row['subscription_id'], 'plan' => $row['package_name'],
            'allowed_installations' => (int)$row['max_installations'], 'business_name' => $row['business_name'],
            'hosting_type' => $row['hosting_mode'], 'domain' => $row['domain'], 'activated' => $bound,
            'activated_at' => $row['activated_at'] === null ? null : (int)$row['activated_at'],
            'expires_at' => self::effectiveExpiry($row) ?: null, 'update_entitled' => $state === 'active' && (bool)$row['updates_enabled'],
            'updates_enabled' => (bool)$row['updates_enabled'], 'channel' => $row['channel'], 'auto_update' => $row['auto_update'],
            'installed_version' => $version, 'last_check_in' => $row['last_check_in'] === null ? null : (int)$row['last_check_in'],
            'health' => $row['health'], 'current_release' => $current === null ? null : self::releaseData($current),
            'latest_release' => $latest === null ? null : self::releaseData($latest),
            'available_release' => !$allowed ? null : self::releaseData($candidate), 'update_allowed' => $allowed,
            'update_status' => $reason, 'automatic_update_allowed' => false,
            'automatic_policy_eligible' => $allowed && $row['auto_update'] === $row['channel']
                && (bool)$candidate['automatic_compatible'] && !(bool)$candidate['migration_required']
                && $row['health'] === 'maintenance' && (int)$row['last_check_in'] >= $now - 300 && !$autoBlocked,
            'managed_update' => $managed, 'last_update' => $attempt, 'last_successful_update' => $activity['last_success'] ?? null,
            'last_failed_update' => $activity['last_failure'] ?? null,
        ];
    }

    public static function server(FMDatabase $db, string $action, array $input, string $credential): array
    {
        self::ready($db);
        if (!preg_match('/^Bearer (fm1_[a-f0-9]{64})$/D', $credential, $match)) {
            throw new FMError(401, 'invalid_credential', 'Installation credentials are invalid.');
        }
        $installation = self::id($input, 'installation_id');
        $instance = self::id($input, 'instance_id');
        $hash = hash('sha256', $match[1]);
        $record = self::record($db, $installation);
        if ($record === null || !hash_equals($record['credential_hash'], $hash)) {
            throw new FMError(401, 'invalid_credential', 'Installation credentials are invalid.');
        }
        FMSecurity::rateLimit($db, 'license_server:' . $installation, 120, 300);
        return $db->transaction(static function () use ($db, $action, $input, $installation, $instance, $hash, $record): array {
            self::lockOwner($db, $record['user_id']);
            $row = self::record($db, $installation);
            if (!hash_equals($row['credential_hash'], $hash) || ($row['instance_id'] !== null && !hash_equals($row['instance_id'], $instance))) {
                throw new FMError(401, 'invalid_credential', 'Installation credentials are invalid.');
            }
            $domain = $input['domain'] ?? null;
            if (!is_string($domain) || !hash_equals($row['domain'], strtolower(rtrim($domain, '.')))) {
                throw new FMError(403, 'domain_mismatch', 'Gelato must use the registered installation hostname.');
            }
            $now = time();
            if ($action === 'activate' && $row['instance_id'] === null) {
                if (self::licenseState($row, $now) !== 'active' || !self::deploymentReady($row)) {
                    throw new FMError(409, 'activation_unavailable', 'An active license and verified, active installation are required.');
                }
                $db->execute('UPDATE fm_licenses SET instance_id = ?, activated_at = ?, updated_at = ? WHERE id = ?',
                    [$instance, $now, $now, $row['id']]);
                FMSecurity::audit($db, $row['user_id'], 'license.installation_activated', ['license_id' => $row['id']]);
            } elseif ($row['instance_id'] === null) {
                throw new FMError(409, 'activation_required', 'Activate this Gelato installation first.');
            }
            if (in_array($action, ['activate', 'status', 'heartbeat'], true)) {
                $version = $input['installed_version'] ?? null;
                if ($version !== null && !self::validVersion($version)) {
                    throw new FMError(422, 'invalid_version', 'Gelato sent an invalid installed version.');
                }
                $health = self::choice($input + ['health' => 'online'], 'health', ['online', 'degraded', 'maintenance']);
                $managed = $row['hosting_mode'] === 'cloud' ? FMManagedUpdates::active($db, $installation) : null;
                if ($managed !== null && $managed['phase'] !== 'queued') {
                    $version = $row['installed_version'];
                    $health = $row['health'];
                }
                $db->execute('UPDATE fm_licenses SET installed_version = ?, health = ?, last_check_in = ? WHERE id = ?', [$version, $health, $now, $row['id']]);
            } elseif ($action === 'report') {
                self::report($db, $row, $input, $now);
            } else {
                throw new FMError(404, 'action_not_found', 'This installation action does not exist.');
            }
            return self::snapshot($db, self::record($db, $installation), self::published($db));
        });
    }

    private static function report(FMDatabase $db, array $row, array $input, int $now): void
    {
        $id = self::id($input, 'attempt_id');
        $state = self::choice($input, 'state', ['started', 'completed', 'failed']);
        $attempt = $db->one('SELECT a.*, r.version FROM fm_update_attempts a JOIN fm_releases r ON r.id = a.release_id WHERE a.id = ? AND a.license_id = ? AND a.instance_id = ?', [$id, $row['id'], $row['instance_id']]);
        if (!$attempt) {
            throw new FMError(404, 'attempt_not_found', 'This update attempt does not exist for this installation.');
        }
        if ($attempt['mode'] === 'managed') {
            throw new FMError(409, 'worker_owned_attempt', 'Food Monster records the result of a managed update.');
        }
        if ($attempt['state'] === $state) {
            return;
        }
        if ($attempt['state'] !== 'started' || $state === 'started') {
            throw new FMError(409, 'attempt_finished', 'This update attempt already has a final outcome.');
        }
        $version = $input['installed_version'] ?? null;
        if ($state === 'completed' && $version !== $attempt['version']) {
            throw new FMError(422, 'version_mismatch', 'The completed version must match the approved release.');
        }
        // Store a fixed diagnostic. Arbitrary logs can contain credentials or local paths.
        $diagnostic = null;
        if ($state === 'failed') {
            $diagnostic = match ($input['failure_code'] ?? '') {
                'checksum' => 'The package checksum did not match.',
                'deployment' => 'Gelato files could not be deployed.',
                'migration' => 'The Gelato database upgrade failed.',
                'health' => 'Gelato did not pass its post-update health check.',
                default => 'The installation reported an update failure.',
            };
        }
        if ($db->execute("UPDATE fm_update_attempts SET state = ?, error_summary = ?, finished_at = ? WHERE id = ? AND state = 'started'",
            [$state, $diagnostic, $now, $id]) !== 1) {
            throw new FMError(409, 'attempt_finished', 'This update attempt already has a final outcome.');
        }
        if ($state === 'completed') {
            $db->execute('UPDATE fm_licenses SET installed_version = ?, last_check_in = ? WHERE id = ?', [$version, $now, $row['id']]);
        }
    }

    public static function manualDownload(FMDatabase $db, array $config, array $input, array $actor): array
    {
        self::ready($db);
        FMSecurity::requireVerified($actor);
        FMSecurity::rateLimit($db, 'manual_release:' . $actor['id'], 12, 300);
        $installation = self::id($input, 'installation_id');
        $releaseId = self::id($input, 'release_id');
        $owner = self::installation($db, $installation, $actor)['user_id'];
        $release = $db->one("SELECT * FROM fm_releases WHERE id = ? AND status = 'published'", [$releaseId]);
        $row = self::record($db, $installation);
        if (!$release || !$row || (self::snapshot($db, $row, self::published($db))['available_release']['id'] ?? null) !== $releaseId) {
            throw new FMError(409, 'update_unavailable', 'That release is no longer available for this installation.');
        }
        [$handle, $checksum, $bytes] = self::openPackage($config, $release['package_name']);
        try {
            if (!hash_equals($release['checksum'], $checksum) || (int)$release['package_bytes'] !== $bytes) {
                throw new FMError(409, 'package_changed', 'The release ZIP no longer matches the published checksum and size.');
            }
            $attempt = $db->transaction(static function () use ($db, $actor, $installation, $releaseId, $owner): string {
                self::lockOwner($db, $owner);
                // Lock the exact release before recording approval so withdrawal and approval have a defined order.
                $release = $db->one('SELECT status FROM fm_releases WHERE id = ?' . ($db->driver === 'mysql' ? ' FOR UPDATE' : ''), [$releaseId]);
                $row = self::record($db, $installation);
                if (!$release || $release['status'] !== 'published' || !$row
                    || (self::snapshot($db, $row, self::published($db))['available_release']['id'] ?? null) !== $releaseId) {
                    throw new FMError(409, 'update_unavailable', 'That release is no longer available for this installation.');
                }
                // Heal approvals from releases withdrawn before this upgrade.
                $db->execute("UPDATE fm_update_attempts SET state = 'failed', error_summary = 'The release was withdrawn.', finished_at = ?
                    WHERE license_id = ? AND state = 'started' AND mode <> 'managed' AND release_id IN (SELECT id FROM fm_releases WHERE status = 'withdrawn')",
                    [time(), $row['id']]);
                $existing = $db->one("SELECT id, release_id FROM fm_update_attempts WHERE license_id = ? AND instance_id = ? AND state = 'started' LIMIT 1",
                    [$row['id'], $row['instance_id']]);
                if ($existing) {
                    if ($existing['release_id'] !== $releaseId) {
                        throw new FMError(409, 'update_in_progress', 'Record the outcome of the existing update before approving another release.');
                    }
                    return $existing['id'];
                }
                $id = $db->id();
                $db->execute("INSERT INTO fm_update_attempts (id, license_id, release_id, instance_id, mode, state, started_at) VALUES (?, ?, ?, ?, 'manual', 'started', ?)",
                    [$id, $row['id'], $releaseId, $row['instance_id'], time()]);
                $db->execute('UPDATE fm_licenses SET latest_attempt_id = ? WHERE id = ?', [$id, $row['id']]);
                FMSecurity::audit($db, $actor['id'], 'updates.manual_approved', ['license_id' => $row['id'], 'release_id' => $releaseId, 'attempt_id' => $id]);
                return $id;
            });
        } catch (Throwable $error) {
            fclose($handle);
            throw $error;
        }
        return [$handle, $bytes, 'gelato-' . $release['version'] . '-' . $release['channel'] . '.zip', $attempt];
    }

    private static function managedRelease(FMDatabase $db, array $config, array $release): bool
    {
        if (!FMManagedUpdates::enabled($config) || $release['channel'] !== 'stable'
            || !(bool)$release['automatic_compatible'] || (bool)$release['migration_required']) {
            return false;
        }
        try {
            self::assertReleaseSignature($db, $config, $release);
            return true;
        } catch (FMError) {
            return false;
        }
    }

    private static function approveManagedUpdate(FMDatabase $db, array $config, array $input, array $actor): array
    {
        if (!FMLicensingUpgrade::updatesReady($db)) {
            throw new FMError(503, 'upgrade_required', 'Run the Food Monster managed update upgrade first.');
        }
        FMSecurity::requireVerified($actor);
        FMSecurity::rateLimit($db, 'managed_release:' . $actor['id'], 12, 300);
        $installation = self::id($input, 'installation_id');
        $releaseId = self::id($input, 'release_id');
        if (self::installation($db, $installation, $actor)['user_id'] !== $actor['id']) {
            throw new FMError(403, 'owner_required', 'The restaurant owner must approve the hosted update.');
        }
        return $db->transaction(static function () use ($db, $config, $installation, $releaseId, $actor): array {
            self::lockOwner($db, $actor['id']);
            $release = $db->one('SELECT * FROM fm_releases WHERE id = ?' . ($db->driver === 'mysql' ? ' FOR UPDATE' : ''), [$releaseId]);
            $pending = FMManagedUpdates::active($db, $installation);
            if ($pending !== null) {
                if ($pending['release_id'] === $releaseId && $pending['phase'] !== 'recovery') {
                    return ['attempt_id' => $pending['attempt_id'], 'phase' => $pending['phase']];
                }
                throw new FMError(409, 'update_in_progress', 'Wait for the pending hosted update before approving another.');
            }
            $license = self::record($db, $installation);
            $setup = $db->one("SELECT state FROM fm_hosted_setups WHERE installation_id = ?", [$installation]);
            if (!$release || !$license || ($setup['state'] ?? null) !== 'ready' || !self::managedRelease($db, $config, $release)
                || (self::snapshot($db, $license, self::published($db))['available_release']['id'] ?? null) !== $releaseId) {
                throw new FMError(409, 'managed_update_unavailable', 'This installation and release are not eligible for a managed update.');
            }
            if ($db->one("SELECT id FROM fm_update_attempts WHERE license_id = ? AND state = 'started'", [$license['id']])) {
                throw new FMError(409, 'update_in_progress', 'Resolve the pending manual update before approving a hosted update.');
            }
            $id = $db->id();
            $now = time();
            $db->execute("INSERT INTO fm_update_attempts (id, license_id, release_id, instance_id, mode, state, started_at)
                VALUES (?, ?, ?, ?, 'managed', 'started', ?)", [$id, $license['id'], $releaseId, $license['instance_id'], $now]);
            $db->execute("INSERT INTO fm_managed_updates (attempt_id, installation_id, from_version, phase, updated_at)
                VALUES (?, ?, ?, 'queued', ?)", [$id, $installation, $license['installed_version'], $now]);
            $db->execute('UPDATE fm_licenses SET latest_attempt_id = ? WHERE id = ?', [$id, $license['id']]);
            FMSecurity::audit($db, $actor['id'], 'updates.managed_approved', ['installation_id' => $installation, 'release_id' => $releaseId, 'attempt_id' => $id]);
            return ['attempt_id' => $id, 'phase' => 'queued'];
        });
    }

    public static function authorizeManagedWork(FMDatabase $db, array $config, array $job, callable $callback): void
    {
        $db->transaction(static function () use ($db, $config, $job, $callback): void {
            self::lockOwner($db, $job['user_id']);
            $release = $db->one('SELECT * FROM fm_releases WHERE id = ?' . ($db->driver === 'mysql' ? ' FOR UPDATE' : ''), [$job['release_id']]);
            $row = self::record($db, $job['installation_id']);
            if (!$release || $release['status'] !== 'published' || !$row || !self::managedRelease($db, $config, $release)
                || $row['user_id'] !== $job['user_id'] || $row['id'] !== $job['license_id'] || $row['instance_id'] !== $job['instance_id']
                || $row['installed_version'] !== $job['from_version'] || $row['hosting_mode'] !== 'cloud'
                || $row['channel'] !== $release['channel'] || !(bool)$row['updates_enabled']
                || self::licenseState($row, time()) !== 'active' || !self::deploymentReady($row)) {
                throw new FMError(409, 'managed_update_unavailable', 'The release or installation is no longer eligible for this update.');
            }
            $callback();
        });
    }

    private static function inspectPackage(array $config, string $name, string $version): array
    {
        [$handle, $checksum, $bytes, $path] = self::openPackage($config, $name);
        try {
            self::validateArchive($path, $version);
            clearstatcache(true, $path);
            $opened = fstat($handle);
            $current = stat($path);
            foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
                if (!$current || $opened[$field] !== $current[$field]) {
                    throw new FMError(409, 'package_changed', 'The release ZIP changed during validation.');
                }
            }
        } finally {
            fclose($handle);
        }
        return [$checksum, $bytes];
    }

    public static function hostedPackage(FMDatabase $db, array $config, ?string $releaseId): array
    {
        $hosting = $config['hosting'] ?? [];
        $version = $hosting['release_version'] ?? null;
        if ($releaseId === null && (!self::validVersion($version) || str_contains($version, '-'))) {
            throw new FMError(409, 'hosted_release_required', 'Select a signed stable version in the private hosting settings.');
        }
        $column = $releaseId === null ? 'version' : 'id';
        $row = $db->one("SELECT * FROM fm_releases WHERE $column = ? AND status = 'published' AND channel = 'stable'", [$releaseId ?? $version]);
        if ($row === null) {
            throw new FMError(409, 'hosted_release_unavailable', 'Publish the selected signed Gelato hosted release.');
        }
        self::assertReleaseSignature($db, $config, $row);
        [$checksum, $bytes] = self::inspectPackage($config, $row['package_name'], $row['version']);
        if (!hash_equals((string)$row['checksum'], $checksum) || (int)$row['package_bytes'] !== $bytes) {
            throw new FMError(409, 'package_changed', 'The stable package differs from its published checksum.');
        }
        return [$row['id'], $row['version'], self::packageDirectory($config) . '/' . $row['package_name'], $checksum];
    }

    private static function storedSignature(FMDatabase $db, string $id): ?array
    {
        return FMLicensingUpgrade::releaseIntakeReady($db)
            ? $db->one('SELECT signature FROM fm_signed_releases WHERE release_id = ?', [$id]) : null;
    }

    public static function assertReleaseSignature(FMDatabase $db, array $config, array $release): void
    {
        $stored = self::storedSignature($db, $release['id']);
        self::assertHostedSignature($config, $release['version'], (string)$release['checksum'], $stored['signature'] ?? null);
    }

    public static function assertHostedSignature(array $config, string $version, string $sha, ?string $detached = null): void
    {
        $hosting = $config['hosting'] ?? [];
        $publicKey = base64_decode((string)($hosting['release_public_key'] ?? ''), true);
        $signature = base64_decode(($detached ?? (string)($hosting['release_signatures'][$version] ?? '')), true);
        if (!function_exists('sodium_crypto_sign_verify_detached')
            || !is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !preg_match('/^[a-f0-9]{64}$/D', $sha)
            || !sodium_crypto_sign_verify_detached($signature, "Gelato Hosted Release v1\n{$version}\n{$sha}\n", $publicKey)) {
            throw new FMError(409, 'hosted_signature_invalid', 'The hosted ZIP does not match a trusted signature for this version.');
        }
    }

    public static function canClaimHosted(FMDatabase $db, string $id): bool
    {
        $license = self::record($db, $id);
        return $license === null || ($license['state'] === 'active' && $license['instance_id'] === null && $license['activated_at'] === null);
    }

    public static function activateHosted(FMDatabase $db, string $id, string $domain, string $version, string $instanceId, string $key): void
    {
        $record = self::record($db, $id);
        $hash = hash('sha256', $key);
        if ($record === null) {
            $now = time();
            $db->execute("INSERT INTO fm_licenses (id, installation_id, credential_hash, state, created_at, updated_at) VALUES (?, ?, ?, 'active', ?, ?)",
                [$db->id(), $id, $hash, $now, $now]);
        } elseif (!hash_equals($record['credential_hash'], $hash)) {
            if (!self::canClaimHosted($db, $id)) {
                throw new FMError(409, 'license_conflict', 'The managed installation already has a bound or inactive license.');
            }
            $db->execute('UPDATE fm_licenses SET credential_hash = ?, updated_at = ? WHERE id = ?', [$hash, time(), $record['id']]);
            FMSecurity::audit($db, $record['user_id'], 'license.hosted_key_issued', ['license_id' => $record['id']]);
        }
        self::server($db, 'activate', [
            'installation_id' => $id, 'instance_id' => $instanceId, 'domain' => $domain, 'installed_version' => $version,
        ], 'Bearer ' . $key);
    }

    private static function validateArchive(string $path, string $version): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new FMError(503, 'zip_support_required', 'Enable the PHP zip extension to publish release packages.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new FMError(422, 'invalid_package', 'The release ZIP is invalid or inconsistent.');
        }
        try {
            $seen = [];
            $total = 0;
            if ($zip->numFiles > 15000) {
                throw new FMError(422, 'package_limits', 'The release ZIP exceeds the supported entry count.');
            }
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $entry = $zip->statIndex($index);
                $name = (string)($entry['name'] ?? '');
                $canonical = strtolower(rtrim($name, '/'));
                $parts = explode('/', $canonical);
                $total += (int)($entry['size'] ?? 0);
                if ($name === '' || str_contains($name, chr(92)) || preg_match('~[\x00-\x1F\x7F:]|[ .](?:/|$)~', $name)
                    || array_intersect($parts, ['', '.', '..', '.git', 'config.php', '.env', 'storage', 'uploads', 'gelato-private-storage'])
                    || isset($seen[$canonical]) || $total > 1073741824 || (int)($entry['encryption_method'] ?? 0) !== 0
                    || !$zip->getExternalAttributesIndex($index, $os, $attributes)
                    || ($os === ZipArchive::OPSYS_UNIX && !in_array(($attributes >> 16) & 0170000, [0, 0100000, 0040000], true))) {
                    throw new FMError(422, 'unsafe_package', 'The ZIP contains a protected path, unsupported entry, or excessive unpacked size.');
                }
                $seen[$canonical] = true;
            }
            if ($zip->locateName('index.php') === false || $zip->locateName('upgrade.php') === false
                || $zip->locateName('includes/bootstrap.php') === false
                || trim((string)$zip->getFromName('GELATO_VERSION.txt', 100)) !== $version) {
                throw new FMError(422, 'package_version_mismatch', 'The ZIP must contain the Gelato app at its root and a matching GELATO_VERSION.txt.');
            }
        } finally {
            $zip->close();
        }
    }

    private static function packageDirectory(array $config): string
    {
        $configured = $config['releases']['private_directory'] ?? '';
        $appRoot = dirname(__DIR__);
        $documentRoot = PHP_SAPI === 'cli' ? $appRoot : realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $directory = is_string($configured) && str_starts_with($configured, '/') ? realpath($configured) : false;
        if (!$directory || !$documentRoot || !is_dir($directory) || $directory === '/'
            || $directory === $documentRoot || str_starts_with($directory, $documentRoot . '/')
            || $directory === $appRoot || str_starts_with($directory, $appRoot . '/')) {
            throw new FMError(503, 'release_storage_unavailable', 'Configure a release directory outside the public web root.');
        }
        return $directory;
    }

    private static function openPackage(array $config, string $name): array
    {
        $path = self::packageDirectory($config) . '/' . $name;
        if (!is_file($path) || is_link($path) || realpath($path) !== $path || !($handle = @fopen($path, 'rb'))) {
            throw new FMError(409, 'package_missing', 'The release ZIP could not be opened in the private release directory.');
        }
        $size = (int)(fstat($handle)['size'] ?? 0);
        if ($size < 22 || $size > 536870912 || fread($handle, 4) !== "PK\x03\x04" || !rewind($handle)) {
            fclose($handle);
            throw new FMError(422, 'invalid_package', 'A nonempty ZIP package up to 512 MiB is required.');
        }
        $context = hash_init('sha256');
        if (hash_update_stream($context, $handle, $size + 1) !== $size || !rewind($handle)) {
            fclose($handle);
            throw new FMError(409, 'package_changed', 'The release ZIP changed while it was being read.');
        }
        return [$handle, hash_final($context), $size, $path];
    }
}
