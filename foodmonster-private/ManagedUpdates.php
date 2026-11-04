<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMManagedUpdates
{
    private const PENDING = "('queued', 'swapping', 'recovery')";
    private const RETRY_DELAY = 60;

    public static function enabled(array $config): bool
    {
        return ($config['hosting']['managed_updates_enabled'] ?? false) === true;
    }

    public static function active(FMDatabase $db, string $installation): ?array
    {
        if (!FMLicensingUpgrade::updatesReady($db)) {
            return null;
        }
        return $db->one('SELECT u.attempt_id, u.phase, a.release_id
            FROM fm_managed_updates u JOIN fm_update_attempts a ON a.id = u.attempt_id
            WHERE u.installation_id = ? AND u.phase IN ' . self::PENDING, [$installation]);
    }

    public static function due(FMDatabase $db): array
    {
        if (!FMLicensingUpgrade::updatesReady($db)) {
            return [];
        }
        return $db->query("SELECT attempt_id, installation_id FROM fm_managed_updates
            WHERE phase = 'queued' OR (phase = 'swapping' AND updated_at <= ?)
            ORDER BY CASE WHEN phase = 'swapping' THEN 0 ELSE 1 END, updated_at, attempt_id LIMIT 25",
            [time() - self::RETRY_DELAY]);
    }

    public static function job(FMDatabase $db, string $id): array
    {
        if (!FMLicensingUpgrade::updatesReady($db)) {
            throw new FMError(503, 'upgrade_required', 'Run the Food Monster update analytics upgrade first.');
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new FMError(422, 'invalid_identifier', 'Provide a valid update attempt ID.');
        }
        $job = $db->one('SELECT u.*, a.release_id, a.license_id, a.instance_id, r.version, i.user_id
            FROM fm_managed_updates u JOIN fm_update_attempts a ON a.id = u.attempt_id
            JOIN fm_releases r ON r.id = a.release_id JOIN fm_installations i ON i.id = u.installation_id
            WHERE u.attempt_id = ?', [$id]);
        if (!$job) {
            throw new FMError(404, 'attempt_not_found', 'This hosted update attempt does not exist.');
        }
        return $job;
    }

    public static function finish(FMDatabase $db, array $job, string $phase, ?string $message): void
    {
        $db->transaction(static function () use ($db, $job, $phase, $message): void {
            if ($db->driver === 'mysql') {
                $db->one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$job['user_id']]);
            }
            $now = time();
            $state = $phase === 'completed' ? 'completed' : 'failed';
            $db->execute('UPDATE fm_managed_updates SET phase = ?, updated_at = ? WHERE attempt_id = ?', [$phase, $now, $job['attempt_id']]);
            $db->execute("UPDATE fm_update_attempts SET state = ?, error_summary = ?, finished_at = ? WHERE id = ? AND mode = 'managed'",
                [$state, $message, $now, $job['attempt_id']]);
            if ($phase === 'completed' || $phase === 'rolled_back') {
                $version = $phase === 'completed' ? $job['version'] : $job['from_version'];
                $db->execute("UPDATE fm_licenses SET installed_version = ?, health = 'online', last_check_in = ?, updated_at = ?
                    WHERE id = ? AND instance_id = ?", [$version, $now, $now, $job['license_id'], $job['instance_id']]);
            }
            FMSecurity::audit($db, $job['user_id'], 'updates.managed_' . $phase, [
                'installation_id' => $job['installation_id'], 'release_id' => $job['release_id'], 'attempt_id' => $job['attempt_id'],
            ]);
        });
    }

    public static function recordDownload(FMDatabase $db, string $attempt): void
    {
        if (!FMLicensingUpgrade::updatesReady($db)) {
            return;
        }
        $db->execute("INSERT INTO fm_release_download_events (id, release_id, served_at)
            SELECT ?, release_id, ? FROM fm_update_attempts WHERE id = ? AND mode = 'manual'", [$db->id(), time(), $attempt]);
    }

    public static function analytics(FMDatabase $db): array
    {
        FMSecurity::requireAdmin($db);
        if (!FMLicensingUpgrade::updatesReady($db)) {
            throw new FMError(503, 'upgrade_required', 'Run the Food Monster update analytics upgrade first.');
        }
        $registry = $db->query("SELECT id, version, channel, status, published_at FROM fm_releases
            WHERE status IN ('published', 'withdrawn') ORDER BY published_at DESC, id DESC");
        $outcomes = array_column($db->query("SELECT release_id, COUNT(*) AS requested,
                SUM(pending) AS pending,
                SUM(CASE WHEN pending = 0 THEN succeeded ELSE 0 END) AS succeeded,
                SUM(CASE WHEN pending = 0 AND succeeded = 0 THEN 1 ELSE 0 END) AS failed
            FROM (SELECT release_id, license_id,
                    MAX(CASE WHEN state = 'started' THEN 1 ELSE 0 END) AS pending,
                    MAX(CASE WHEN state = 'completed' THEN 1 ELSE 0 END) AS succeeded
                FROM fm_update_attempts GROUP BY release_id, license_id) AS installations
            GROUP BY release_id"), null, 'release_id');
        $downloads = array_column($db->query('SELECT release_id, COUNT(*) AS total FROM fm_release_download_events GROUP BY release_id'), 'total', 'release_id');
        $rollbacks = array_column($db->query("SELECT a.release_id, COUNT(*) AS total
            FROM fm_managed_updates u JOIN fm_update_attempts a ON a.id = u.attempt_id
            WHERE u.phase = 'rolled_back' GROUP BY a.release_id"), 'total', 'release_id');
        $versions = $db->query("SELECT l.channel, l.installed_version AS version, COUNT(*) AS total
            FROM fm_licenses l JOIN fm_installations i ON i.id = l.installation_id
            WHERE l.instance_id IS NOT NULL AND i.released_at IS NULL AND i.status = 'active'
            GROUP BY l.channel, l.installed_version ORDER BY total DESC, l.channel, l.installed_version");
        $fleet = [];
        foreach ($versions as $version) {
            $fleet[$version['channel'] . ':' . ($version['version'] ?? '')] = (int)$version['total'];
        }
        $releases = [];
        foreach ($registry as $release) {
            $id = $release['id'];
            $counts = $outcomes[$id] ?? [];
            $releases[] = $release + [
                'requested' => (int)($counts['requested'] ?? 0), 'pending' => (int)($counts['pending'] ?? 0),
                'succeeded' => (int)($counts['succeeded'] ?? 0), 'failed' => (int)($counts['failed'] ?? 0),
                'downloads' => (int)($downloads[$id] ?? 0), 'rollbacks' => (int)($rollbacks[$id] ?? 0),
                'reported_installed' => $fleet[$release['channel'] . ':' . $release['version']] ?? 0,
            ];
        }
        return ['releases' => $releases, 'versions' => $versions];
    }
}
