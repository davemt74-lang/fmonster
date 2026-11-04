<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMSubdomains
{
    private const RENEWAL_WINDOW = 30 * 86400;
    private const TRANSFER_WINDOW = 7 * 86400;
    private const RETIREMENT_GRACE = 30 * 86400;

    public static function dispatch(string $action, array $input, FMDatabase $db): ?array
    {
        if (!in_array($action, ['subdomains.renew', 'subdomains.relink', 'subdomains.offer', 'subdomains.respond', 'admin.subdomain_offer'], true)) {
            return null;
        }
        if (FMLicensingUpgrade::commercialSince($db) === null) {
            throw new FMError(503, 'upgrade_required', 'Run the Food Monster billing and subdomain upgrade first.');
        }
        $admin = $action === 'admin.subdomain_offer';
        $actor = $admin ? FMSecurity::requireAdmin($db) : FMSecurity::requireUser($db);
        FMSecurity::requireVerified($actor);
        FMSecurity::rateLimit($db, 'subdomain_manage:' . $actor['id'], 30, 3600);
        return match ($action) {
            'subdomains.renew' => self::renew($db, $input, $actor),
            'subdomains.relink' => self::relink($db, $input, $actor),
            'subdomains.respond' => self::respond($db, $input, $actor),
            default => self::offer($db, $input, $actor, $admin),
        };
    }

    public static function expiry(FMDatabase $db, array $site, ?int $since = null): ?int
    {
        if ($site['hosting_mode'] !== 'cloud') {
            return null;
        }
        $since ??= FMLicensingUpgrade::commercialSince($db);
        if ($since === null) {
            return null;
        }
        $renewal = $db->one('SELECT expires_at FROM fm_subdomain_terms WHERE installation_id = ?', [$site['id']]);
        return $renewal ? (int)$renewal['expires_at'] : self::anniversary(max((int)$site['created_at'], $since));
    }

    public static function assertCurrent(FMDatabase $db, array $site): void
    {
        $expiry = self::expiry($db, $site);
        if ($expiry !== null && $expiry <= time()) {
            throw new FMError(409, 'subdomain_expired', 'Renew this subdomain registration before starting hosting setup.');
        }
    }

    public static function assertNoTransfer(FMDatabase $db, array $site): void
    {
        if ($site['hosting_mode'] === 'cloud' && FMLicensingUpgrade::commercialSince($db) !== null && self::pending($db, $site['id'])) {
            throw new FMError(409, 'transfer_pending', 'Resolve the pending subdomain transfer first.');
        }
    }

    public static function decorate(FMDatabase $db, array $sites): array
    {
        $since = FMLicensingUpgrade::commercialSince($db);
        if ($since === null) {
            return $sites;
        }
        foreach ($sites as &$site) {
            if ($site['hosting_mode'] !== 'cloud' || $site['status'] === 'released') {
                continue;
            }
            $expiry = self::expiry($db, $site, $since);
            $pending = self::pending($db, $site['id']);
            $site['subdomain_registration'] = [
                'expires_at' => $expiry,
                'state' => $expiry <= time() ? 'expired' : 'registered',
                'renewal_opens_at' => $expiry - self::RENEWAL_WINDOW,
                'transfer' => $pending ? ['id' => $pending['id'], 'expires_at' => (int)$pending['expires_at']] : null,
                'can_transfer' => !$pending && $expiry > time() && self::transferable($db, $site),
                'can_relink' => !$pending && $expiry > time() && !self::linkedPlanActive($db, $site),
            ];
        }
        return $sites;
    }

    public static function incoming(FMDatabase $db, string $recipient): array
    {
        if (FMLicensingUpgrade::commercialSince($db) === null) {
            return [];
        }
        return $db->query("SELECT t.id, t.expires_at, i.domain, u.name AS sender_name FROM fm_subdomain_transfers t
            JOIN fm_installations i ON i.id = t.installation_id JOIN users u ON u.id = t.sender_id
            WHERE t.recipient_id = ? AND t.state = 'pending' AND t.expires_at > ? ORDER BY t.expires_at",
            [$recipient, time()]);
    }

    // Used by bounded queues with aliases i (installation) and t (renewal term).
    public static function expirySql(FMDatabase $db, int $since): string
    {
        $base = "(CASE WHEN i.created_at > $since THEN i.created_at ELSE $since END)";
        $anniversary = $db->driver === 'mysql'
            ? "UNIX_TIMESTAMP(DATE_ADD(FROM_UNIXTIME($base), INTERVAL 1 YEAR))"
            : "CAST(strftime('%s', $base, 'unixepoch', '+1 year',
                CASE WHEN strftime('%m-%d', $base, 'unixepoch') = '02-29' THEN '-1 day' ELSE '+0 days' END) AS INTEGER)";
        return "COALESCE(t.expires_at, $anniversary)";
    }

    public static function canReviewRetirement(FMDatabase $db, array $site, int $expiry, int $now): bool
    {
        return $site['status'] === 'reserved' && $expiry + self::RETIREMENT_GRACE <= $now
            && self::transferable($db, $site) && self::pending($db, $site['id']) === null;
    }

    public static function retire(FMDatabase $db, array $config, array $input, array $admin): array
    {
        FMSecurity::requireVerified($admin);
        FMSecurity::rateLimit($db, 'subdomain_retirement:' . $admin['id'], 12, 3600);
        $id = self::id($input, 'installation_id');
        $confirmation = $input['confirm_domain'] ?? '';
        $note = FMAuth::text($input, 'note', 2000);
        if (strlen($note) < 10) {
            throw new FMError(422, 'retirement_note_required', 'Record at least 10 characters about the retirement review.');
        }
        $site = self::site($db, $id, $admin, true);
        $parent = (string)$config['base_domain'];
        $label = $site['subdomain'];
        if (!is_string($confirmation) || $confirmation !== $site['domain'] || !is_string($label)
            || !preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $label) || strlen($label) > 40
            || !filter_var($parent, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || "$label.$parent" !== $site['domain']) {
            throw new FMError(422, 'retirement_confirmation', 'Type the exact Cloud hostname to confirm this review.');
        }
        $expiry = self::expiry($db, $site);
        if ($expiry === null || !self::canReviewRetirement($db, $site, $expiry, time())) {
            throw new FMError(409, 'retirement_ineligible', 'Retirement requires an unused reservation at least 30 days past expiry with no pending transfer.');
        }
        [, $tenant, $private] = FMHostingPreflight::paths($config);
        $connection = FMHostingSettings::connection($db, $config);
        if (empty($connection['tested_at'])) {
            throw new FMError(409, 'cpanel_test_required', 'Test the saved cPanel connection before reviewing retirement.');
        }
        $cpanel = new FMCpanel($connection, $config);
        $cpanel->assertDomainUnused($parent, $site['domain']);
        $lookup = ($config['app_env'] ?? '') === 'test' ? ($config['hosting']['test_retirement_dns'] ?? null) : null;
        $records = is_callable($lookup) ? $lookup($site['domain']) : (function_exists('dns_get_record')
            ? @dns_get_record($site['domain'] . '.', DNS_A | DNS_AAAA | DNS_CNAME) : false);
        if ($records !== []) {
            throw new FMError(409, 'retirement_dns_present', 'Retirement requires a successful DNS check with no address or alias records.');
        }

        return $db->transaction(static function () use ($db, $id, $site, $admin, $tenant, $private, $connection, $note): array {
            FMCommerce::lockUser($db, $site['user_id']);
            $current = self::site($db, $id, $admin, true);
            $expiry = self::expiry($db, $current);
            $settings = $db->one('SELECT hostname, username, encrypted_token, tested_at FROM fm_hosting_settings WHERE id = 1'
                . ($db->driver === 'mysql' ? ' FOR UPDATE' : ''));
            $expectedSettings = ['hostname' => $connection['hostname'], 'username' => $connection['username'],
                'encrypted_token' => $connection['encrypted_token'], 'tested_at' => $connection['tested_at']];
            if ($current['user_id'] !== $site['user_id'] || $current['domain'] !== $site['domain'] || $expiry === null
                || !self::canReviewRetirement($db, $current, $expiry, time())
                || $settings !== $expectedSettings) {
                throw new FMError(409, 'retirement_changed', 'The registration or cPanel connection changed. Refresh and review it again.');
            }
            foreach ([$tenant => $current['subdomain'], $private => $id] as $root => $name) {
                $path = "$root/$name";
                clearstatcache(true, $path);
                if (!is_readable($root) || !is_executable($root) || @lstat($path) !== false) {
                    throw new FMError(409, 'retirement_files_present', 'The hosting directories must be accessible and contain no files for this registration.');
                }
            }
            $now = time();
            if ($db->execute('DELETE FROM fm_domain_claims WHERE installation_id = ? AND domain = ?', [$id, $current['domain']]) !== 1
                || $db->execute("UPDATE fm_installations SET status = 'released', released_at = ?, updated_at = ? WHERE id = ? AND released_at IS NULL",
                    [$now, $now, $id]) !== 1) {
                throw new FMError(409, 'retirement_changed', 'The registration changed. Refresh and review it again.');
            }
            FMSecurity::audit($db, $admin['id'], 'subdomain.retired', ['installation_id' => $id,
                'owner_id' => $current['user_id'], 'domain' => $current['domain'], 'expired_at' => $expiry, 'note' => $note]);
            return ['domain' => $current['domain'], 'released_at' => $now];
        });
    }

    private static function anniversary(int $start): int
    {
        $date = new DateTimeImmutable('@' . $start);
        $year = (int)$date->format('Y') + 1;
        $month = (int)$date->format('n');
        $day = (int)$date->format('j');
        // A February 29 registration renews on February 28 in a non-leap year.
        return $date->setDate($year, $month, checkdate($month, $day, $year) ? $day : 28)->getTimestamp();
    }

    private static function renew(FMDatabase $db, array $input, array $actor): array
    {
        $id = self::id($input, 'installation_id');
        return $db->transaction(static function () use ($db, $actor, $id, $input): array {
            FMCommerce::lockUser($db, $actor['id']);
            $site = self::site($db, $id, $actor, false);
            self::assertNoTransfer($db, $site);
            $expiry = self::expiry($db, $site);
            $now = time();
            if (($input['expected_expires_at'] ?? null) !== $expiry || $expiry - self::RENEWAL_WINDOW > $now) {
                throw new FMError(409, 'renewal_unavailable', 'Refresh your registration. Renewals open 30 days before expiry.');
            }
            $subscription = FMCommerce::selectSubscription($db, $actor['id'], 'cloud', null, $site);
            $next = self::anniversary(max($expiry, $now));
            $sql = $db->driver === 'mysql' ? 'ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)' : 'ON CONFLICT(installation_id) DO UPDATE SET expires_at = excluded.expires_at';
            $db->execute('INSERT INTO fm_subdomain_terms (installation_id, expires_at) VALUES (?, ?) ' . $sql, [$id, $next]);
            if ($site['subscription_id'] !== $subscription['id']) {
                $db->execute('UPDATE fm_installations SET subscription_id = ?, updated_at = ? WHERE id = ?', [$subscription['id'], $now, $id]);
            }
            FMSecurity::audit($db, $actor['id'], 'subdomain.renewed', ['installation_id' => $id, 'expires_at' => $next]);
            return ['expires_at' => $next];
        });
    }

    private static function relink(FMDatabase $db, array $input, array $actor): array
    {
        $id = self::id($input, 'installation_id');
        return $db->transaction(static function () use ($db, $actor, $id): array {
            FMCommerce::lockUser($db, $actor['id']);
            $site = self::site($db, $id, $actor, false);
            self::assertNoTransfer($db, $site);
            self::assertCurrent($db, $site);
            if (self::linkedPlanActive($db, $site)) {
                throw new FMError(409, 'plan_already_active', 'This subdomain already has an active Cloud plan.');
            }
            $subscription = FMCommerce::selectSubscription($db, $actor['id'], 'cloud', null, $site);
            $db->execute('UPDATE fm_installations SET subscription_id = ?, updated_at = ? WHERE id = ?', [$subscription['id'], time(), $id]);
            FMSecurity::audit($db, $actor['id'], 'subdomain.plan_linked', ['installation_id' => $id, 'subscription_id' => $subscription['id']]);
            return ['subscription_id' => $subscription['id']];
        });
    }

    private static function linkedPlanActive(FMDatabase $db, array $site): bool
    {
        return $db->one("SELECT id FROM fm_subscriptions WHERE id = ? AND status = 'active' AND expires_at > ?",
            [$site['subscription_id'], time()]) !== null;
    }

    private static function offer(FMDatabase $db, array $input, array $actor, bool $admin): array
    {
        $id = self::id($input, 'installation_id');
        $owner = self::site($db, $id, $actor, $admin)['user_id'];
        $email = $input['recipient_email'] ?? '';
        $recipient = is_string($email) && strlen($email) <= 254 && filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            ? $db->one('SELECT id, email_verified_at FROM users WHERE email = ?', [strtolower(trim($email))])
            : null;
        if (!$recipient || empty($recipient['email_verified_at']) || $recipient['id'] === $owner) {
            throw new FMError(422, 'recipient_unavailable', 'Enter the verified email of a different Food Monster account.');
        }
        return $db->transaction(static function () use ($db, $id, $actor, $admin, $owner, $recipient): array {
            self::lockParties($db, $owner, $recipient['id']);
            self::requireVerifiedRecipient($db, $recipient['id']);
            $site = self::site($db, $id, $actor, $admin);
            if ($site['user_id'] !== $owner || !self::transferable($db, $site)) {
                throw new FMError(409, 'transfer_unavailable', 'Only unused subdomains without a license or setup job can be transferred here.');
            }
            self::assertCurrent($db, $site);
            self::assertNoTransfer($db, $site);
            $transfer = $db->id();
            $expiry = min(time() + self::TRANSFER_WINDOW, self::expiry($db, $site));
            $db->execute("INSERT INTO fm_subdomain_transfers (id, installation_id, sender_id, recipient_id, state, expires_at)
                VALUES (?, ?, ?, ?, 'pending', ?)", [$transfer, $id, $owner, $recipient['id'], $expiry]);
            FMSecurity::audit($db, $actor['id'], 'subdomain.transfer_offered', ['installation_id' => $id, 'transfer_id' => $transfer, 'recipient_id' => $recipient['id']]);
            return ['transfer_id' => $transfer, 'expires_at' => $expiry];
        });
    }

    private static function respond(FMDatabase $db, array $input, array $actor): array
    {
        $id = self::id($input, 'transfer_id');
        $decision = $input['decision'] ?? null;
        if (!in_array($decision, ['accept', 'decline', 'cancel'], true)) {
            throw new FMError(422, 'invalid_decision', 'Choose accept, decline, or cancel.');
        }
        $transfer = self::transferForActor($db, $id, $actor['id'], $decision);
        return $db->transaction(static function () use ($db, $id, $actor, $decision, $transfer): array {
            self::lockParties($db, $transfer['sender_id'], $transfer['recipient_id']);
            $transfer = self::transferForActor($db, $id, $actor['id'], $decision);
            $state = ['accept' => 'accepted', 'decline' => 'declined', 'cancel' => 'cancelled'][$decision];
            if ($transfer['state'] === $state) {
                return ['state' => $state];
            }
            if ($transfer['state'] !== 'pending' || (int)$transfer['expires_at'] <= time()) {
                throw new FMError(409, 'transfer_closed', 'This transfer is no longer pending.');
            }
            $siteId = $transfer['installation_id'];
            if ($decision === 'accept') {
                self::requireVerifiedRecipient($db, $actor['id']);
                $site = self::site($db, $siteId, ['id' => $transfer['sender_id']], false);
                self::assertCurrent($db, $site);
                if (!self::transferable($db, $site)) {
                    throw new FMError(409, 'transfer_unavailable', 'This subdomain now has a license or hosting activity.');
                }
                $subscription = FMCommerce::selectSubscription($db, $actor['id'], 'cloud');
                $profile = $db->one('SELECT business_name FROM business_profiles WHERE user_id = ?', [$actor['id']]);
                $business = trim((string)($profile['business_name'] ?? ''));
                $db->execute("UPDATE fm_installations SET user_id = ?, subscription_id = ?, business_name = ?, status = 'reserved',
                    verification_token = ?, verified_at = NULL, admin_note = NULL, updated_at = ? WHERE id = ?",
                    [$actor['id'], $subscription['id'], $business !== '' ? $business : $actor['name'], bin2hex(random_bytes(24)), time(), $siteId]);
            }
            $db->execute('UPDATE fm_subdomain_transfers SET state = ? WHERE id = ?', [$state, $id]);
            FMSecurity::audit($db, $actor['id'], 'subdomain.transfer_' . $state, ['installation_id' => $siteId, 'transfer_id' => $id]);
            return ['state' => $state];
        });
    }

    private static function requireVerifiedRecipient(FMDatabase $db, string $id): void
    {
        $recipient = $db->one('SELECT email_verified_at FROM users WHERE id = ?', [$id]);
        if (empty($recipient['email_verified_at'])) {
            throw new FMError(403, 'recipient_unverified', 'The receiving account must verify its email before the subdomain can be transferred.');
        }
    }

    private static function transferForActor(FMDatabase $db, string $id, string $actor, string $decision): array
    {
        $row = $db->one('SELECT * FROM fm_subdomain_transfers WHERE id = ?', [$id]);
        $party = $decision === 'cancel' ? 'sender_id' : 'recipient_id';
        if (!$row || $row[$party] !== $actor) {
            throw new FMError(404, 'transfer_missing', 'This transfer was not found.');
        }
        return $row;
    }

    private static function pending(FMDatabase $db, string $id): ?array
    {
        return $db->one("SELECT id, expires_at FROM fm_subdomain_transfers WHERE installation_id = ? AND state = 'pending' AND expires_at > ?", [$id, time()]);
    }

    private static function transferable(FMDatabase $db, array $site): bool
    {
        return in_array($site['status'], ['reserved', 'verification_pending', 'verified'], true)
            && empty($site['deployment_started_at']) && empty($site['activated_at'])
            && !$db->one('SELECT installation_id FROM fm_hosted_setups WHERE installation_id = ?', [$site['id']])
            && !$db->one('SELECT id FROM fm_licenses WHERE installation_id = ?', [$site['id']]);
    }

    private static function site(FMDatabase $db, string $id, array $actor, bool $admin): array
    {
        $site = $db->one("SELECT i.* FROM fm_installations i JOIN fm_domain_claims c ON c.installation_id = i.id AND c.domain = i.domain
            WHERE i.id = ? AND i.hosting_mode = 'cloud' AND i.released_at IS NULL", [$id]);
        if (!$site || (!$admin && $site['user_id'] !== $actor['id'])) {
            throw new FMError(404, 'subdomain_missing', 'This Cloud registration was not found.');
        }
        return $site;
    }

    private static function lockParties(FMDatabase $db, string $sender, string $recipient): void
    {
        $parties = [$sender, $recipient];
        sort($parties, SORT_STRING);
        foreach ($parties as $party) {
            FMCommerce::lockUser($db, $party);
        }
    }

    private static function id(array $input, string $field): string
    {
        $id = $input[$field] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new FMError(422, 'invalid_identifier', 'Choose a valid subdomain or transfer.');
        }
        return $id;
    }
}
