<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMHostedOperations
{
    private const BATCH_SIZE = 25;
    private const DUE = "COALESCE(c.due_at, CASE WHEN s.state = 'queued' THEN s.created_at ELSE s.updated_at + 60 END)";
    private const MESSAGES = [
        'cpanel_connection_failed' => 'cPanel is temporarily unreachable. Check server connectivity if this persists.',
        'cpanel_dns_unavailable' => 'The cPanel hostname could not be resolved to a public IP address.',
        'cpanel_auth_failed' => 'Check the saved cPanel token and its account permissions.',
        'cpanel_request_rejected' => 'Check cPanel account permissions and resource limits.',
        'cpanel_domain_missing' => 'The cPanel account must own the configured parent domain.',
        'hosting_conflict' => 'Review the existing domain, directory, database, or database user.',
        'parent_root_unverified' => 'Check the parent domain’s document root in cPanel.',
        'parent_root_conflict' => 'Move the tenant or private directory outside the parent document root.',
        'hosting_settings_changed' => 'Restore the original hosting settings before retrying.',
        'document_root_mismatch' => 'cPanel assigned a different document root. Review the account policy.',
        'document_root_conflict' => 'Review the unexpected files or links in the tenant document root.',
        'hosted_release_required' => 'Choose a signed hosted release in the private settings.',
        'hosted_release_unavailable' => 'Publish the selected signed hosted release.',
        'release_unavailable' => 'The pinned hosted release is unavailable.',
        'hosted_signature_invalid' => 'Check the pinned public key, signature, and release ZIP.',
        'untrusted_staging' => 'Cancel and clean up the older, unverified staging files before requeueing.',
        'seed_failed' => 'Check the tenant database, PHP CLI extensions, and hosted installer.',
        'setup_ineligible' => 'Check the Cloud subscription and owner setup details.',
        'subdomain_expired' => 'The owner must renew the subdomain before setup can resume.',
        'https_pending' => 'Waiting for a trusted certificate and the Gelato health check.',
        'https_timeout' => 'HTTPS or Gelato health did not pass within the scheduled checks.',
        'cleanup_pending' => 'Remove the assigned hosting resources before releasing the reservation.',
        'cleanup_unverified' => 'Restore the original hosting settings before verifying cleanup.',
        'worker_interrupted' => 'The worker stopped before saving progress. Check the cron log if this repeats.',
        'worker_error' => 'Review the worker log and correct the issue before retrying.',
    ];

    public function __construct(private readonly FMDatabase $db)
    {
    }

    public static function dispatch(string $action, array $input, FMDatabase $db): ?array
    {
        if (!in_array($action, ['admin.hosted_jobs', 'admin.hosted_timeline', 'admin.hosted_command'], true)) {
            return null;
        }
        $admin = FMSecurity::requireAdmin($db);
        if (!FMLicensingUpgrade::operationsReady($db)) {
            throw new FMError(503, 'upgrade_required', 'Run the hosting operations database upgrade.');
        }
        $ops = new self($db);
        if ($action === 'admin.hosted_jobs') {
            $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
            if ($page === false) {
                throw new FMError(422, 'invalid_page', 'Choose a valid job page.');
            }
            return $ops->listing($page);
        }
        $id = $input['installation_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new FMError(422, 'invalid_installation', 'Provide a valid installation ID.');
        }
        if ($action === 'admin.hosted_timeline') {
            $job = $db->one('SELECT created_at FROM fm_hosted_setups WHERE installation_id = ?', [$id]);
            if (!$job) {
                throw new FMError(404, 'setup_missing', 'The installation has no hosted setup request.');
            }
            $events = $db->query('SELECT event, stage, reason, created_at FROM fm_hosted_events WHERE installation_id = ? ORDER BY id DESC LIMIT 29', [$id]);
            $events[] = ['event' => 'queued', 'stage' => 'queued', 'reason' => null, 'created_at' => $job['created_at']];
            return ['events' => array_map(static fn(array $event): array => $event + ['detail' => self::detail($event['reason'])], $events)];
        }
        FMSecurity::rateLimit($db, 'hosted_commands:' . $admin['id'], 30, 300);
        return $ops->request($id, $input['command'] ?? '', $admin['id']);
    }

    private function listing(int $page): array
    {
        $rows = $this->db->query(
            'SELECT s.installation_id, s.state, s.stage, s.created_at, s.updated_at, i.domain, i.business_name, i.released_at,
                c.command, c.due_at, c.failures, c.reason, r.version
            FROM fm_hosted_setups s JOIN fm_installations i ON i.id = s.installation_id
            LEFT JOIN fm_hosted_control c ON c.installation_id = s.installation_id
            LEFT JOIN fm_releases r ON r.id = s.release_id
            ORDER BY s.created_at DESC, s.installation_id DESC LIMIT ? OFFSET ?',
            [self::BATCH_SIZE + 1, ($page - 1) * self::BATCH_SIZE]
        );
        return ['page' => $page, 'has_more' => count($rows) > self::BATCH_SIZE,
            'jobs' => array_map(static fn(array $job): array => $job + [
                'detail' => self::detail($job['reason']),
                'actions' => $job['command'] === null ? self::actions($job['state'], $job['released_at']) : [],
            ], array_slice($rows, 0, self::BATCH_SIZE))];
    }

    public function due(): array
    {
        $due = self::DUE;
        return array_column($this->db->query(
            "SELECT s.installation_id FROM fm_hosted_setups s LEFT JOIN fm_hosted_control c ON c.installation_id = s.installation_id
            WHERE (c.command IS NOT NULL OR s.state IN ('queued','working','awaiting_https','retry_wait')) AND $due <= ?
            ORDER BY $due, s.created_at, s.installation_id LIMIT 25", [time()]
        ), 'installation_id');
    }

    public function begin(string $id, bool $scheduled): string
    {
        return $this->db->transaction(function () use ($id, $scheduled): string {
            $job = $this->lockedJob($id);
            if ($scheduled && ($job['due_at'] ?? ((int)$job['updated_at'] + ($job['state'] === 'queued' ? 0 : 60))) > time()) {
                return 'skipped';
            }
            if (in_array($job['command'], ['cancel', 'release'], true)) {
                return $job['command'];
            }
            if ($job['state'] === 'ready' || $job['state'] === 'cancelled') {
                return $scheduled ? 'skipped' : $job['state'];
            }
            if ($scheduled && $job['state'] === 'failed' && $job['command'] !== 'retry') {
                return 'skipped';
            }
            if ($scheduled && $job['state'] === 'working') {
                // The caller holds the filesystem lock. A due working row is an interrupted run.
                return $this->failure($id, 'worker_interrupted');
            }
            $manual = !$scheduled || $job['command'] === 'retry';
            $this->db->execute("UPDATE fm_hosted_setups SET state = 'working', updated_at = ? WHERE installation_id = ?", [time(), $id]);
            $this->save($job, null, time() + 600, $manual ? 0 : (int)$job['failures'], $manual ? null : $job['reason']);
            $this->event($id, 'started', $job['stage']);
            return 'run';
        });
    }

    public function progress(string $id, string $stage, string $event = 'stage'): void
    {
        $this->db->transaction(function () use ($id, $stage, $event): void {
            $job = $this->lockedJob($id);
            $this->save($job, null, $event === 'stage' ? time() + 600 : null, 0, null);
            $this->event($id, $event, $stage);
        });
    }

    public function failure(string $id, string $reason, bool $command = false): string
    {
        return $this->db->transaction(function () use ($id, $reason, $command): string {
            $job = $this->lockedJob($id);
            $reason = array_key_exists($reason, self::MESSAGES) ? $reason : 'worker_error';
            $attempt = (int)$job['failures'] + 1;
            $https = $reason === 'https_pending';
            $transient = !$command && in_array($reason, ['cpanel_connection_failed', 'cpanel_dns_unavailable', 'https_pending', 'worker_interrupted'], true);
            $due = $transient && $attempt < ($https ? 24 : 6)
                ? time() + min($https ? 3600 : 900, 60 * (2 ** min(6, $attempt - 1)))
                : null;
            if ($https && $due === null) {
                $reason = 'https_timeout';
            }
            $state = $job['state'] === 'cancelled' ? 'cancelled' : ($due === null ? 'failed' : ($https ? 'awaiting_https' : 'retry_wait'));
            $this->db->execute('UPDATE fm_hosted_setups SET state = ?, updated_at = ? WHERE installation_id = ?', [$state, time(), $id]);
            $this->save($job, null, $due, $attempt, $reason);
            $this->event($id, $command ? 'command_failed' : $state, $job['stage'], $reason);
            return $state;
        });
    }

    private function request(string $id, mixed $command, string $admin): array
    {
        return $this->db->transaction(function () use ($id, $command, $admin): array {
            $job = $this->lockedJob($id);
            if ($job['command'] !== null && $job['command'] === $command) {
                return ['command' => $command];
            }
            if ($job['command'] !== null || !in_array($command, self::actions($job['state'], $job['released_at']), true)) {
                throw new FMError(409, 'hosted_command_unavailable', 'This action is unavailable for the current job state. Refresh the queue.');
            }
            $this->save($job, $command, time(), (int)$job['failures'], $job['reason']);
            $this->event($id, $command . '_requested', $job['stage']);
            FMSecurity::audit($this->db, $admin, 'hosting.' . $command . '_requested', ['installation_id' => $id]);
            return ['command' => $command];
        });
    }

    private static function actions(string $state, mixed $released): array
    {
        if ($released !== null || $state === 'ready') {
            return [];
        }
        return match ($state) {
            'queued' => ['cancel'],
            'failed', 'awaiting_https', 'retry_wait' => ['retry', 'cancel'],
            'cancelled' => ['release'],
            default => [],
        };
    }

    private function lockedJob(string $id): array
    {
        $job = $this->db->one('SELECT s.installation_id, s.state, s.stage, s.updated_at, i.released_at
            FROM fm_hosted_setups s JOIN fm_installations i ON i.id = s.installation_id WHERE s.installation_id = ?'
            . ($this->db->driver === 'mysql' ? ' FOR UPDATE' : ''), [$id]);
        if (!$job) {
            throw new FMError(404, 'setup_missing', 'The installation has no hosted setup request.');
        }
        $control = $this->db->one('SELECT command, due_at, failures, reason FROM fm_hosted_control WHERE installation_id = ?', [$id]);
        return $job + ['has_control' => $control !== null] + ($control ?? ['command' => null, 'due_at' => null, 'failures' => 0, 'reason' => null]);
    }

    private function save(array $job, ?string $command, ?int $due, int $failures, ?string $reason): void
    {
        $sql = $job['has_control']
            ? 'UPDATE fm_hosted_control SET command = ?, due_at = ?, failures = ?, reason = ? WHERE installation_id = ?'
            : 'INSERT INTO fm_hosted_control (command, due_at, failures, reason, installation_id) VALUES (?, ?, ?, ?, ?)';
        $this->db->execute($sql, [$command, $due, $failures, $reason, $job['installation_id']]);
    }

    private function event(string $id, string $event, string $stage, ?string $reason = null): void
    {
        $this->db->execute('INSERT INTO fm_hosted_events (installation_id, event, stage, reason, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $event, $stage, $reason, time()]);
    }

    private static function detail(?string $reason): ?string
    {
        return $reason === null ? null : (self::MESSAGES[$reason] ?? self::MESSAGES['worker_error']);
    }
}
