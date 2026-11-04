<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMOperatorDashboard
{
    private const LIMIT = 20;
    private const EVENTS = [
        'subscription.requested' => 'Subscription requested',
        'subscription.stripe_paid' => 'Stripe payment verified',
        'subscription.stripe_checkout_recovered' => 'Checkout recovered',
        'subscription.activated_manually' => 'Subscription approved',
        'installation.registered' => 'Domain registered',
        'installation.hosted_setup_submitted' => 'Owner setup submitted',
        'installation.hosted_setup_completed' => 'Site went live',
        'installation.hosted_setup_cancelled' => 'Setup cancelled',
        'subdomain.renewed' => 'Subdomain renewed',
        'subdomain.transfer_accepted' => 'Subdomain transfer accepted',
    ];

    public static function dispatch(string $action, array $input, FMDatabase $db, array $config): ?array
    {
        if (!in_array($action, ['admin.operations', 'admin.customer_timeline', 'admin.stripe_recover'], true)) {
            return null;
        }
        FMSecurity::requireAdmin($db);
        if (!FMLicensingUpgrade::operatorReady($db)) {
            throw new FMError(503, 'upgrade_required', 'Run the operator dashboard database upgrade.');
        }
        return match ($action) {
            'admin.operations' => self::listing($db, $input, $config),
            'admin.customer_timeline' => self::timeline($db, $input),
            'admin.stripe_recover' => FMStripeBilling::recover($db, $config, $input, true),
        };
    }

    private static function listing(FMDatabase $db, array $input, array $config): array
    {
        $view = $input['view'] ?? 'setup';
        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($page === false || !in_array($view, ['setup', 'hosting', 'billing', 'domains', 'customers'], true)) {
            throw new FMError(422, 'invalid_filter', 'Choose a valid operations view and page.');
        }
        $email = $input['email'] ?? '';
        if (!is_string($email) || strlen($email) > 254 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new FMError(422, 'invalid_email', 'Enter a complete customer email address.');
        }
        $now = time();
        $expiry = FMSubdomains::expirySql($db, (int)FMLicensingUpgrade::commercialSince($db));
        $customer = 'u.id AS customer_id, u.name AS customer_name, u.email';
        $site = "SELECT $customer, i.id, i.domain, i.status, i.created_at, i.updated_at";
        [$sql, $params, $sort] = match ($view) {
            'setup' => ["SELECT $customer, s.id, s.package_name, s.expires_at, s.activated_at AS updated_at
                FROM fm_subscriptions s JOIN users u ON u.id = s.user_id
                WHERE s.hosting_mode = 'cloud' AND s.status = 'active' AND s.expires_at > ?
                AND NOT EXISTS (SELECT 1 FROM fm_installations i LEFT JOIN fm_hosted_setups h ON h.installation_id = i.id
                    WHERE i.subscription_id = s.id AND i.released_at IS NULL
                    AND (h.installation_id IS NOT NULL OR i.status <> 'reserved' OR i.deployment_started_at IS NOT NULL))", [$now], 's.activated_at DESC, s.id'],
            'hosting' => ["$site, h.state, h.stage, c.reason, c.command
                FROM fm_hosted_setups h JOIN fm_installations i ON i.id = h.installation_id JOIN users u ON u.id = i.user_id
                LEFT JOIN fm_hosted_control c ON c.installation_id = i.id
                WHERE i.released_at IS NULL AND h.state IN ('failed','retry_wait','awaiting_https')", [], 'i.updated_at DESC, i.id'],
            'billing' => ["SELECT $customer, s.id, s.package_name, s.updated_at, b.state, r.reason
                FROM fm_stripe_billing b JOIN fm_subscriptions s ON s.id = b.subscription_id JOIN users u ON u.id = s.user_id
                LEFT JOIN fm_billing_reviews r ON r.subscription_id = s.id
                WHERE (r.subscription_id IS NOT NULL OR b.state = 'past_due'
                    OR (b.state IN ('checkout','confirming') AND s.created_at <= ?))", [$now - 1800], 's.updated_at DESC, s.id'],
            'domains' => ["$site, $expiry AS expires_at
                FROM fm_installations i JOIN users u ON u.id = i.user_id LEFT JOIN fm_subdomain_terms t ON t.installation_id = i.id
                WHERE i.hosting_mode = 'cloud' AND i.released_at IS NULL AND $expiry <= ?", [$now + 30 * 86400], 'expires_at, i.id'],
            'customers' => ["SELECT $customer, u.id, u.created_at AS updated_at FROM users u WHERE u.role = 'customer'", [], 'u.created_at DESC, u.id'],
        };
        if ($email !== '') {
            $sql .= ' AND u.email = ?';
            $params[] = strtolower($email);
        }
        $rows = $db->query("$sql ORDER BY $sort LIMIT ? OFFSET ?", [...$params, self::LIMIT + 1, ($page - 1) * self::LIMIT]);
        $stripe = $view === 'billing' && FMStripeBilling::ready($db, $config);
        foreach ($rows as &$row) {
            $row['can_recover'] = $stripe && $row['state'] === 'checkout';
            $row['can_retry'] = $view === 'hosting' && $row['command'] === null;
        }
        return ['view' => $view, 'page' => $page, 'has_more' => count($rows) > self::LIMIT, 'rows' => array_slice($rows, 0, self::LIMIT)];
    }

    private static function timeline(FMDatabase $db, array $input): array
    {
        $id = $input['customer_id'] ?? '';
        $customer = is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id)
            ? $db->one('SELECT id, name, email, created_at FROM users WHERE id = ?', [$id]) : null;
        if (!$customer) {
            throw new FMError(404, 'customer_missing', 'This customer was not found.');
        }
        $subscriptions = $db->query('SELECT s.package_name, s.status, s.expires_at, b.state AS billing_state, r.reason AS billing_review
            FROM fm_subscriptions s LEFT JOIN fm_stripe_billing b ON b.subscription_id = s.id
            LEFT JOIN fm_billing_reviews r ON r.subscription_id = s.id
            WHERE s.user_id = ? ORDER BY s.created_at DESC, s.id DESC LIMIT 30', [$id]);
        $sites = $db->query('SELECT i.id, i.domain, i.status, i.created_at, i.hosting_mode, h.state AS setup_state
            FROM fm_installations i LEFT JOIN fm_hosted_setups h ON h.installation_id = i.id
            WHERE i.user_id = ? ORDER BY i.created_at DESC, i.id DESC LIMIT 30', [$id]);
        foreach ($sites as &$site) {
            $site['expires_at'] = FMSubdomains::expiry($db, $site);
        }
        $names = array_keys(self::EVENTS);
        $list = implode(',', array_fill(0, count($names), '?'));
        $audits = $db->query("SELECT event, created_at FROM audit_log WHERE user_id = ? AND event IN ($list) ORDER BY created_at DESC, id DESC LIMIT 30", [$id, ...$names]);
        $events = array_map(static fn(array $row): array => ['title' => self::EVENTS[$row['event']], 'at' => (int)$row['created_at']], $audits);
        $events[] = ['title' => 'Account created', 'at' => (int)$customer['created_at']];
        $hosting = $db->query('SELECT e.event, e.stage, e.created_at, i.domain FROM fm_hosted_events e JOIN fm_installations i ON i.id = e.installation_id
            WHERE i.user_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT 30', [$id]);
        foreach ($hosting as $event) {
            $events[] = ['title' => 'Hosting ' . str_replace('_', ' ', $event['event']), 'at' => (int)$event['created_at'], 'domain' => $event['domain']];
        }
        usort($events, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
        return ['customer' => $customer, 'subscriptions' => $subscriptions, 'sites' => $sites, 'events' => array_slice($events, 0, 30)];
    }
}
