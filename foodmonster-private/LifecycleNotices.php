<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMLifecycleNotices
{
    private const KINDS = ['transfer', 'renewal_30', 'renewal_7', 'billing', 'ready'];

    public static function run(FMDatabase $db, array $config): array
    {
        $counts = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0];
        if (($config['mail']['lifecycle_enabled'] ?? false) !== true) {
            return $counts;
        }
        $since = FMLicensingUpgrade::lifecycleSince($db);
        if ($since === null || !FMMail::available($config)) {
            throw new FMError(503, 'notices_unavailable', 'Run the database upgrade and configure lifecycle mail before running the worker.');
        }
        $now = time();
        foreach (self::KINDS as $kind) {
            [$source, $params] = self::source($db, $kind, $now, $since);
            $rows = $db->query("SELECT c.reference_id, c.owner_id, c.cycle FROM ($source) c WHERE NOT EXISTS
                (SELECT 1 FROM fm_lifecycle_notices n WHERE n.kind = ? AND n.reference_id = c.reference_id AND n.owner_id = c.owner_id AND n.cycle = c.cycle)
                ORDER BY c.reference_id LIMIT 20", [...$params, $kind]);
            foreach ($rows as $row) {
                $conflict = $db->driver === 'mysql' ? 'ON DUPLICATE KEY UPDATE kind = fm_lifecycle_notices.kind' : 'ON CONFLICT DO NOTHING';
                $db->execute("INSERT INTO fm_lifecycle_notices (kind, reference_id, owner_id, cycle, state, due_at) VALUES (?,?,?,?,'pending',?) $conflict",
                    [$kind, $row['reference_id'], $row['owner_id'], $row['cycle'], $now]);
            }
        }
        $due = $db->query("SELECT kind, reference_id, owner_id, cycle, attempts FROM fm_lifecycle_notices
            WHERE state = 'pending' AND due_at <= ? ORDER BY due_at, kind, reference_id, owner_id, cycle LIMIT 40", [$now]);
        foreach ($due as $row) {
            $key = [$row['kind'], $row['reference_id'], $row['owner_id'], $row['cycle']];
            if ($db->execute("UPDATE fm_lifecycle_notices SET state = 'sending', attempts = attempts + 1, due_at = ?
                WHERE kind = ? AND reference_id = ? AND owner_id = ? AND cycle = ? AND state = 'pending' AND due_at <= ?", [$now, ...$key, $now]) !== 1) {
                continue;
            }
            [$source, $params] = self::source($db, $row['kind'], time(), $since);
            $target = $db->one("SELECT c.email, c.domain FROM ($source) c WHERE c.reference_id = ? AND c.owner_id = ? AND c.cycle = ?",
                [...$params, $row['reference_id'], $row['owner_id'], $row['cycle']]);
            $next = $now;
            if (!$target) {
                $state = 'skipped';
            } elseif (FMMail::lifecycle($config, $target['email'], $row['kind'], $target['domain'])) {
                $state = 'sent';
            } elseif ((int)$row['attempts'] < 2) {
                $state = 'pending';
                $next = time() + 3600 * (1 + (int)$row['attempts']);
            } else {
                $state = 'failed';
            }
            $db->execute('UPDATE fm_lifecycle_notices SET state = ?, due_at = ? WHERE kind = ? AND reference_id = ? AND owner_id = ? AND cycle = ?',
                [$state, $next, ...$key]);
            $counts[$state === 'pending' ? 'retry' : $state]++;
        }
        return $counts;
    }

    private static function source(FMDatabase $db, string $kind, int $now, int $since): array
    {
        if ($kind === 'transfer') {
            return ["SELECT t.id AS reference_id, u.id AS owner_id, 0 AS cycle, u.email, i.domain FROM fm_subdomain_transfers t
                JOIN fm_installations i ON i.id = t.installation_id JOIN users u ON u.id = t.recipient_id
                WHERE u.email_verified_at IS NOT NULL AND i.released_at IS NULL AND t.sender_id = i.user_id
                AND t.state = 'pending' AND t.expires_at > ?", [$now]];
        }
        if ($kind === 'billing') {
            return ["SELECT s.id AS reference_id, u.id AS owner_id, COALESCE(s.expires_at, 0) AS cycle, u.email, NULL AS domain
                FROM fm_stripe_billing b JOIN fm_subscriptions s ON s.id = b.subscription_id JOIN users u ON u.id = s.user_id
                WHERE u.email_verified_at IS NOT NULL AND b.state = 'past_due'", []];
        }
        if ($kind === 'ready') {
            return ["SELECT i.id AS reference_id, u.id AS owner_id, 0 AS cycle, u.email, i.domain FROM fm_hosted_setups h
                JOIN fm_installations i ON i.id = h.installation_id JOIN users u ON u.id = i.user_id
                WHERE u.email_verified_at IS NOT NULL AND h.state = 'ready' AND i.status = 'active' AND i.released_at IS NULL
                AND h.updated_at >= ?", [max($since, $now - 7 * 86400)]];
        }
        $expiry = FMSubdomains::expirySql($db, (int)FMLicensingUpgrade::commercialSince($db));
        $end = $now + ($kind === 'renewal_7' ? 7 : 30) * 86400;
        $start = $kind === 'renewal_7' ? $now : $now + 7 * 86400;
        return ["SELECT i.id AS reference_id, u.id AS owner_id, $expiry AS cycle, u.email, i.domain FROM fm_installations i
            JOIN users u ON u.id = i.user_id LEFT JOIN fm_subdomain_terms t ON t.installation_id = i.id
            WHERE u.email_verified_at IS NOT NULL AND i.hosting_mode = 'cloud' AND i.released_at IS NULL
            AND $expiry > ? AND $expiry <= ?", [$start, $end]];
    }
}
