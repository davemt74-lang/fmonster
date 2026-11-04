<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMStripeBilling
{
    public const VERSION = '2025-06-30.basil';
    private const META = 'food_monster_subscription_id';
    private const CHECKOUT_RETRY_WINDOW = 23 * 3600;
    private const EVENTS = ['checkout.session.expired', 'customer.subscription.created', 'customer.subscription.updated',
        'customer.subscription.deleted', 'invoice.paid', 'invoice.payment_failed'];

    public static function ready(FMDatabase $db, array $config): bool
    {
        $stripe = $config['stripe'] ?? [];
        $live = ($stripe['mode'] ?? null) === 'live';
        return ($stripe['enabled'] ?? false) === true
            && ($live ? $config['app_env'] === 'production' : ($stripe['mode'] ?? null) === 'test' && $config['app_env'] !== 'production')
            && preg_match('/^(?:sk|rk)_' . ($live ? 'live' : 'test') . '_[A-Za-z0-9]+$/D', (string)($stripe['secret_key'] ?? ''))
            && preg_match('/^whsec_[A-Za-z0-9]+$/D', (string)($stripe['webhook_secret'] ?? ''))
            && function_exists('curl_init') && FMLicensingUpgrade::commercialSince($db) !== null;
    }

    public static function checkoutEnabled(array $config): bool
    {
        return ($config['stripe']['checkout_enabled'] ?? true) === true;
    }

    public static function reserve(FMDatabase $db, string $id, string $email, array $config): void
    {
        $db->execute("INSERT INTO fm_stripe_billing (subscription_id, checkout_email, live_mode, state) VALUES (?, ?, ?, 'checkout')",
            [$id, $email, $config['stripe']['mode'] === 'live']);
    }

    public static function ongoing(FMDatabase $db, string $owner, string $package, string $mode): ?array
    {
        return $db->one("SELECT s.id FROM fm_subscriptions s JOIN fm_stripe_billing b ON b.subscription_id = s.id
            WHERE s.user_id = ? AND s.package_id = ? AND s.hosting_mode = ? AND b.state NOT IN ('cancelled', 'expired') LIMIT 1",
            [$owner, $package, $mode]);
    }

    public static function openCheckout(FMDatabase $db, string $owner, string $package, string $interval, array $config): ?array
    {
        return $db->one("SELECT s.* FROM fm_subscriptions s JOIN fm_stripe_billing b ON b.subscription_id = s.id
            WHERE s.user_id = ? AND s.package_id = ? AND s.billing_interval = ? AND s.status = 'pending_review'
              AND b.state = 'checkout' AND b.live_mode = ? LIMIT 1", [$owner, $package, $interval, $config['stripe']['mode'] === 'live']);
    }

    public static function isStripe(FMDatabase $db, string $id): bool
    {
        return FMLicensingUpgrade::commercialSince($db) !== null
            && $db->one('SELECT subscription_id FROM fm_stripe_billing WHERE subscription_id = ?', [$id]) !== null;
    }

    public static function decorate(FMDatabase $db, array $subscriptions): array
    {
        if (FMLicensingUpgrade::commercialSince($db) === null) {
            return $subscriptions;
        }
        foreach ($subscriptions as &$subscription) {
            $billing = $db->one('SELECT state, stripe_customer FROM fm_stripe_billing WHERE subscription_id = ?', [$subscription['id']]);
            if ($billing) {
                $subscription['billing_provider'] = 'stripe';
                $subscription['billing_status'] = $billing['state'];
                $subscription['can_manage_billing'] = $billing['stripe_customer'] !== null;
            }
        }
        return $subscriptions;
    }

    public static function checkout(FMDatabase $db, array $config, array $plan): array
    {
        return $db->transaction(static function () use ($db, $config, $plan): array {
            FMCommerce::lockUser($db, $plan['user_id']);
            $billing = $db->one('SELECT * FROM fm_stripe_billing WHERE subscription_id = ?', [$plan['id']]);
            if ($billing['state'] !== 'checkout') {
                throw new FMError(409, 'checkout_closed', 'Refresh your subscription. This checkout is no longer open.');
            }
            if ($billing['checkout_id'] !== null) {
                $session = self::request($config, 'GET', '/checkout/sessions/' . $billing['checkout_id']);
            } else {
                if (!self::checkoutEnabled($config)) {
                    throw new FMError(409, 'checkout_paused', 'New Stripe checkout is temporarily paused.');
                }
                // Stripe retains idempotency keys for at least 24 hours. Stop ambiguous retries before that window ends.
                if (time() - (int)$plan['created_at'] >= self::CHECKOUT_RETRY_WINDOW) {
                    throw new FMError(409, 'checkout_review', 'Use Recover checkout on your subscriptions page to check this order with Stripe.');
                }
                $home = $config['app_url'] . '/account.html?section=subscription';
                $session = self::request($config, 'POST', '/checkout/sessions', [
                    'mode' => 'subscription',
                    'payment_method_types' => ['card'],
                    'success_url' => $home . '&checkout=returned',
                    'cancel_url' => $home,
                    'customer_email' => $billing['checkout_email'],
                    'client_reference_id' => $plan['id'],
                    'adaptive_pricing' => ['enabled' => 'false'],
                    'subscription_data' => ['metadata' => [self::META => $plan['id']]],
                    'line_items' => [['quantity' => 1, 'price_data' => [
                        'currency' => strtolower($plan['currency']), 'unit_amount' => (int)$plan['price_cents'],
                        'recurring' => self::recurring($plan['billing_interval']),
                        'product_data' => ['name' => $plan['package_name']],
                    ]]],
                ], 'foodmonster-checkout-' . $plan['id']);
            }
            self::assertCheckout($session, $plan['id'], $billing['checkout_id'], (bool)$billing['live_mode']);
            $db->execute('UPDATE fm_stripe_billing SET checkout_id = ? WHERE subscription_id = ?', [$session['id'], $plan['id']]);
            if (($session['status'] ?? null) === 'expired') {
                self::expireCheckout($db, $billing, $plan['id']);
                return ['message' => 'This checkout expired. Select the plan again to start a new checkout.'];
            }
            if (($session['status'] ?? null) !== 'open') {
                return ['message' => 'Your checkout is awaiting billing confirmation. Open your subscription page for its status.'];
            }
            return ['url' => self::stripeUrl($session['url'] ?? null, 'checkout.stripe.com')];
        });
    }

    public static function recover(FMDatabase $db, array $config, array $input, bool $operator = false): array
    {
        $actor = $operator ? FMSecurity::requireAdmin($db) : FMSecurity::requireUser($db);
        FMSecurity::requireVerified($actor);
        FMSecurity::rateLimit($db, 'billing_recover:' . $actor['id'], 10, 3600);
        if (!self::ready($db, $config)) {
            throw new FMError(409, 'billing_unavailable', 'Stripe billing is unavailable.');
        }
        $id = $input['subscription_id'] ?? null;
        $owner = $operator && self::localId($id) ? (self::billingRow($db, $id)['user_id'] ?? '') : $actor['id'];
        return $db->transaction(static function () use ($db, $config, $actor, $owner, $id): array {
            FMCommerce::lockUser($db, $owner);
            $row = self::localId($id) ? self::billingRow($db, $id) : null;
            if (!$row || $row['user_id'] !== $owner || (bool)$row['live_mode'] !== ($config['stripe']['mode'] === 'live')) {
                throw new FMError(404, 'billing_missing', 'This billing account was not found.');
            }
            if ($row['billing_state'] !== 'checkout' || $row['stripe_id'] !== null) {
                throw new FMError(409, 'checkout_closed', 'Refresh your subscription. This checkout has already been resolved.');
            }
            $session = self::findCheckout($config, $row);
            if ($session !== null) {
                $status = $session['status'] ?? null;
                $subscription = $session['subscription'] ?? null;
                if ($status === 'complete' || $subscription !== null) {
                    if (!self::identifier($subscription, 'sub_')) {
                        throw new FMError(503, 'stripe_checkout_pending', 'Stripe is still confirming this checkout.');
                    }
                    $row['checkout_id'] = $session['id'];
                    $db->execute('UPDATE fm_stripe_billing SET checkout_id = ? WHERE subscription_id = ?', [$session['id'], $id]);
                    self::reconcile($db, $config, $row, $subscription, null, $session);
                    self::clearReview($db, $id);
                    FMSecurity::audit($db, $actor['id'], 'subscription.stripe_checkout_recovered', ['subscription_id' => $id]);
                    return ['message' => 'Stripe checkout checked. Your subscription shows the latest payment status.'];
                }
                if ($status === 'open') {
                    $sessionId = $session['id'];
                    $session = self::request($config, 'POST', '/checkout/sessions/' . $sessionId . '/expire');
                    self::assertCheckout($session, $id, $sessionId, (bool)$row['live_mode']);
                }
                if (($session['status'] ?? null) !== 'expired' || ($session['subscription'] ?? null) !== null) {
                    throw new FMError(503, 'stripe_checkout_pending', 'Stripe could not confirm that this checkout is closed.');
                }
            }
            // An unmatched session was never handed to the customer. The redirect requires a committed checkout ID.
            self::expireCheckout($db, ['state' => $row['billing_state'], 'stripe_id' => $row['stripe_id']], $id);
            self::clearReview($db, $id);
            FMSecurity::audit($db, $actor['id'], 'subscription.stripe_checkout_recovered', ['subscription_id' => $id]);
            return ['message' => 'Checkout cleared. You can select your plan again.'];
        });
    }

    private static function assertCheckout(array $session, string $local, ?string $expected, bool $live): void
    {
        if (!self::identifier($session['id'] ?? null, 'cs_') || ($session['client_reference_id'] ?? null) !== $local
            || ($session['livemode'] ?? null) !== $live || ($session['mode'] ?? null) !== 'subscription'
            || ($expected !== null && $session['id'] !== $expected)) {
            throw new FMError(503, 'stripe_response', 'Stripe checkout could not be matched to your subscription.');
        }
    }

    private static function findCheckout(array $config, array $row): ?array
    {
        $live = (bool)$row['live_mode'];
        if ($row['checkout_id'] !== null) {
            $session = self::request($config, 'GET', '/checkout/sessions/' . $row['checkout_id']);
            self::assertCheckout($session, $row['id'], $row['checkout_id'], $live);
            return $session;
        }
        $created = (int)$row['created_at'];
        $params = ['created' => ['gte' => max(0, $created - 300), 'lte' => $created + self::CHECKOUT_RETRY_WINDOW + 300], 'limit' => 100];
        $match = null;
        for ($page = 0; $page < 3; $page++) {
            $list = self::request($config, 'GET', '/checkout/sessions', $params);
            if (($list['object'] ?? null) !== 'list' || !is_array($list['data'] ?? null) || !is_bool($list['has_more'] ?? null)) {
                break;
            }
            foreach ($list['data'] as $session) {
                if (($session['client_reference_id'] ?? null) !== $row['id']) {
                    continue;
                }
                self::assertCheckout($session, $row['id'], null, $live);
                if ($match !== null && $match['id'] !== $session['id']) {
                    throw new FMError(409, 'checkout_review', 'More than one Stripe session matches this order. Contact billing support.');
                }
                $match = $session;
            }
            if (!$list['has_more']) {
                return $match;
            }
            $last = end($list['data']);
            if (!self::identifier($last['id'] ?? null, 'cs_') || ($params['starting_after'] ?? null) === $last['id']) {
                break;
            }
            $params['starting_after'] = $last['id'];
        }
        throw new FMError(503, 'checkout_review', 'Stripe could not fully check this order. Contact billing support if the problem persists.');
    }

    public static function portal(FMDatabase $db, array $config, array $input): array
    {
        $actor = FMSecurity::requireUser($db);
        FMSecurity::requireVerified($actor);
        FMSecurity::rateLimit($db, 'billing_portal:' . $actor['id'], 15, 3600);
        if (!self::ready($db, $config)) {
            throw new FMError(409, 'billing_unavailable', 'Stripe billing is unavailable.');
        }
        $id = $input['subscription_id'] ?? null;
        $row = self::localId($id) ? $db->one('SELECT b.* FROM fm_stripe_billing b JOIN fm_subscriptions s ON s.id = b.subscription_id
            WHERE s.id = ? AND s.user_id = ?', [$id, $actor['id']]) : null;
        if (!$row || !self::identifier($row['stripe_customer'], 'cus_') || (bool)$row['live_mode'] !== ($config['stripe']['mode'] === 'live')) {
            throw new FMError(404, 'billing_missing', 'This billing account was not found.');
        }
        $session = self::request($config, 'POST', '/billing_portal/sessions', [
            'customer' => $row['stripe_customer'], 'return_url' => $config['app_url'] . '/account.html?section=subscription',
        ]);
        return ['url' => self::stripeUrl($session['url'] ?? null, 'billing.stripe.com')];
    }

    public static function receive(FMDatabase $db, array $config, string $body, string $header): void
    {
        if (!self::ready($db, $config)) {
            throw new FMError(503, 'billing_unavailable', 'Stripe billing is unavailable.');
        }
        self::verify($body, $header, $config['stripe']['webhook_secret']);
        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FMError(400, 'invalid_event', 'Invalid Stripe event.');
        }
        $type = $event['type'] ?? null;
        if (!in_array($type, self::EVENTS, true)) {
            return;
        }
        $live = $config['stripe']['mode'] === 'live';
        if (($event['livemode'] ?? null) !== $live || ($event['api_version'] ?? null) !== self::VERSION || !empty($event['account'])) {
            throw new FMError(400, 'stripe_event_scope', 'The event does not match the configured Stripe mode or API version.');
        }
        $object = $event['data']['object'] ?? [];
        if ($type === 'checkout.session.expired') {
            self::checkoutExpired($db, $object, $live);
            return;
        }
        if (str_starts_with($type, 'invoice.')) {
            $id = $object['parent']['subscription_details']['subscription'] ?? null;
            $local = $object['parent']['subscription_details']['metadata'][self::META] ?? null;
        } else {
            $id = $object['id'] ?? null;
            $local = $object['metadata'][self::META] ?? null;
        }
        if (!self::identifier($id, 'sub_')) {
            return;
        }
        $paidInvoice = $type === 'invoice.paid' ? ($object['id'] ?? '') : null;
        if ($paidInvoice !== null && !self::identifier($paidInvoice, 'in_')) {
            throw new FMError(400, 'invalid_event', 'Stripe did not identify the paid invoice.');
        }
        $plan = $db->one('SELECT s.id, s.user_id FROM fm_subscriptions s JOIN fm_stripe_billing b ON b.subscription_id = s.id
            WHERE b.stripe_id = ?', [$id]);
        if (!$plan && self::localId($local)) {
            $plan = $db->one('SELECT s.id, s.user_id FROM fm_subscriptions s JOIN fm_stripe_billing b ON b.subscription_id = s.id
                WHERE s.id = ?', [$local]);
        }
        if (!$plan) {
            return;
        }
        $failure = $db->transaction(static function () use ($db, $config, $id, $plan, $live, $paidInvoice): ?FMError {
            FMCommerce::lockUser($db, $plan['user_id']);
            $row = self::billingRow($db, $plan['id']);
            if (!$row || (bool)$row['live_mode'] !== $live) {
                return null;
            }
            try {
                if (self::reconcile($db, $config, $row, $id, $paidInvoice)) {
                    self::clearReview($db, $row['id']);
                }
            } catch (FMError $error) {
                if (!in_array($error->errorCode, ['stripe_invoice_mismatch', 'stripe_price_mismatch', 'stripe_subscription_mismatch', 'stripe_customer_mismatch'], true)
                    || !FMLicensingUpgrade::operatorReady($db)) {
                    throw $error;
                }
                $db->execute('DELETE FROM fm_billing_reviews WHERE subscription_id = ?', [$row['id']]);
                $db->execute('INSERT INTO fm_billing_reviews (subscription_id, reason, updated_at) VALUES (?,?,?)', [$row['id'], $error->errorCode, time()]);
                return $error;
            }
            return null;
        });
        if ($failure !== null) {
            throw $failure;
        }
    }

    private static function clearReview(FMDatabase $db, string $id): void
    {
        if (FMLicensingUpgrade::operatorReady($db)) {
            $db->execute('DELETE FROM fm_billing_reviews WHERE subscription_id = ?', [$id]);
        }
    }

    private static function billingRow(FMDatabase $db, string $id): ?array
    {
        return $db->one('SELECT s.*, b.checkout_id, b.stripe_id, b.stripe_customer, b.state AS billing_state, b.live_mode
            FROM fm_subscriptions s JOIN fm_stripe_billing b ON b.subscription_id = s.id WHERE s.id = ?', [$id]);
    }

    private static function reconcile(FMDatabase $db, array $config, array $row, string $id, ?string $paidInvoice, ?array $session = null): bool
    {
        $recovering = $session !== null;
        $terminal = in_array($row['billing_state'], ['cancelled', 'expired'], true);
        if ($terminal && ($paidInvoice === null || $row['stripe_id'] === null)) {
            return false;
        }
        if ($row['stripe_id'] !== null && $row['stripe_id'] !== $id) {
            throw new FMError(409, 'stripe_subscription_mismatch', 'The Stripe subscription does not match the checkout.');
        }
        $live = (bool)$row['live_mode'];
        $remote = self::request($config, 'GET', '/subscriptions/' . $id, $paidInvoice === null ? ['expand[]' => 'latest_invoice'] : []);
        $customer = $remote['customer'] ?? null;
        if (($remote['id'] ?? null) !== $id || ($remote['livemode'] ?? null) !== $live
            || ($remote['metadata'][self::META] ?? null) !== $row['id'] || !self::identifier($customer, 'cus_')) {
            throw new FMError(409, 'stripe_subscription_mismatch', 'The Stripe subscription could not be verified.');
        }
        if ($row['stripe_id'] === null) {
            if ($row['checkout_id'] === null) {
                throw new FMError(503, 'stripe_checkout_pending', 'The checkout record is awaiting confirmation.');
            }
            $session ??= self::request($config, 'GET', '/checkout/sessions/' . $row['checkout_id']);
            self::assertCheckout($session, $row['id'], $row['checkout_id'], $live);
            if (($session['subscription'] ?? null) !== $id || ($session['customer'] ?? null) !== $customer) {
                throw new FMError(503, 'stripe_checkout_pending', 'The checkout is awaiting Stripe confirmation.');
            }
        } elseif ($row['stripe_customer'] !== $customer) {
            throw new FMError(409, 'stripe_customer_mismatch', 'The Stripe customer could not be verified.');
        }
        $invoice = $remote['latest_invoice'] ?? null;
        if ($paidInvoice !== null) {
            $invoice = self::request($config, 'GET', '/invoices/' . $paidInvoice);
            if (($invoice['id'] ?? null) !== $paidInvoice || ($invoice['livemode'] ?? null) !== $live
                || ($invoice['customer'] ?? null) !== $customer || ($invoice['parent']['subscription_details']['subscription'] ?? null) !== $id) {
                throw new FMError(409, 'stripe_invoice_mismatch', 'The paid invoice could not be matched to this subscription.');
            }
            if (($invoice['status'] ?? null) !== 'paid') {
                throw new FMError(503, 'stripe_invoice_pending', 'Stripe has not confirmed the invoice payment yet.');
            }
        }
        $paidEvidence = $paidInvoice !== null || ($recovering && ($invoice['status'] ?? null) === 'paid');
        self::applySnapshot($db, $row, $remote, $customer, is_array($invoice) ? $invoice : [], $paidEvidence);
        return true;
    }

    private static function applySnapshot(FMDatabase $db, array $plan, array $remote, string $customer, array $invoice, bool $paidEvidence): void
    {
        $state = match ($remote['status'] ?? null) {
            'active' => !empty($remote['cancel_at_period_end']) ? 'ending' : 'active',
            'incomplete', 'trialing' => 'confirming',
            'past_due', 'unpaid', 'paused' => 'past_due',
            'canceled' => 'cancelled',
            'incomplete_expired' => 'expired',
            default => throw new FMError(503, 'stripe_status', 'Stripe returned an unsupported subscription status.'),
        };
        if (in_array($plan['billing_state'], ['cancelled', 'expired'], true)) {
            $state = $plan['billing_state'];
        }
        $id = $remote['id'];
        $paidUntil = $paidEvidence || !in_array($state, ['cancelled', 'expired'], true) ? self::paidUntil($plan, $remote, $invoice) : 0;
        if ($paidEvidence && $paidUntil === 0) {
            throw new FMError(409, 'stripe_invoice_mismatch', 'The paid invoice could not be matched to the package and billing period.');
        }
        $previous = (int)$plan['expires_at'];
        $now = time();
        if ($state === 'active' && $previous === 0 && $paidUntil <= $now) {
            $state = 'confirming';
        }
        if ($paidUntil > $previous && $paidUntil > $now) {
            $db->execute("UPDATE fm_subscriptions SET status = 'active', expires_at = ?, activated_at = COALESCE(activated_at, ?), updated_at = ? WHERE id = ?",
                [$paidUntil, $now, $now, $plan['id']]);
            FMSecurity::audit($db, $plan['user_id'], 'subscription.stripe_paid', ['subscription_id' => $plan['id'], 'expires_at' => $paidUntil]);
        } elseif (in_array($state, ['cancelled', 'expired'], true) && $plan['status'] === 'pending_review') {
            $db->execute("UPDATE fm_subscriptions SET status = 'checkout_expired', updated_at = ? WHERE id = ?", [$now, $plan['id']]);
        }
        $db->execute('UPDATE fm_stripe_billing SET stripe_id = ?, stripe_customer = ?, state = ? WHERE subscription_id = ?',
            [$id, $customer, $state, $plan['id']]);
    }

    private static function paidUntil(array $plan, array $remote, array $invoice): int
    {
        $items = $remote['items']['data'] ?? [];
        $item = count($items) === 1 && empty($remote['items']['has_more']) ? $items[0] : [];
        $price = $item['price'] ?? [];
        $currency = strtolower($plan['currency']);
        $recurring = self::recurring($plan['billing_interval']);
        if (($item['quantity'] ?? null) !== 1 || ($price['unit_amount'] ?? null) !== (int)$plan['price_cents']
            || ($price['currency'] ?? null) !== $currency || ($price['recurring']['interval'] ?? null) !== $recurring['interval']
            || ($price['recurring']['interval_count'] ?? null) !== $recurring['interval_count']) {
            throw new FMError(409, 'stripe_price_mismatch', 'The Stripe price or billing interval differs from the package.');
        }
        if (($invoice['status'] ?? null) !== 'paid' || ($invoice['currency'] ?? null) !== $currency
            || ($invoice['customer'] ?? null) !== $remote['customer'] || ($invoice['amount_paid'] ?? 0) < (int)$plan['price_cents']
            || ($invoice['parent']['subscription_details']['subscription'] ?? null) !== $remote['id']) {
            return 0;
        }
        foreach ($invoice['lines']['data'] ?? [] as $line) {
            $parent = $line['parent']['subscription_item_details'] ?? [];
            $period = $line['period'] ?? [];
            if (($parent['subscription_item'] ?? null) === $item['id'] && ($parent['proration'] ?? null) === false
                && ($line['pricing']['price_details']['price'] ?? null) === $price['id']
                && is_int($period['start'] ?? null) && is_int($period['end'] ?? null) && $period['end'] > $period['start']) {
                return $period['end'];
            }
        }
        return 0;
    }

    private static function checkoutExpired(FMDatabase $db, array $session, bool $live): void
    {
        $id = $session['client_reference_id'] ?? null;
        if (!self::localId($id) || !self::identifier($session['id'] ?? null, 'cs_') || ($session['status'] ?? null) !== 'expired'
            || ($session['mode'] ?? null) !== 'subscription' || ($session['livemode'] ?? null) !== $live) {
            return;
        }
        $plan = $db->one('SELECT user_id FROM fm_subscriptions WHERE id = ?', [$id]);
        if (!$plan) {
            return;
        }
        $db->transaction(static function () use ($db, $plan, $id, $session, $live): void {
            FMCommerce::lockUser($db, $plan['user_id']);
            $billing = $db->one('SELECT * FROM fm_stripe_billing WHERE subscription_id = ?', [$id]);
            if ($billing && (bool)$billing['live_mode'] === $live && ($billing['checkout_id'] === null || $billing['checkout_id'] === $session['id'])) {
                self::expireCheckout($db, $billing, $id);
            }
        });
    }

    private static function expireCheckout(FMDatabase $db, array $billing, string $id): void
    {
        if ($billing['state'] !== 'checkout' || $billing['stripe_id'] !== null) {
            return;
        }
        $db->execute("UPDATE fm_stripe_billing SET state = 'expired' WHERE subscription_id = ?", [$id]);
        $db->execute("UPDATE fm_subscriptions SET status = 'checkout_expired', updated_at = ? WHERE id = ? AND status = 'pending_review'", [time(), $id]);
    }

    private static function verify(string $body, string $header, string $secret): void
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't' && ctype_digit($value) && strlen($value) <= 12) {
                $timestamp = (int)$value;
            }
            if ($name === 'v1' && preg_match('/^[a-f0-9]{64}$/D', $value)) {
                $signatures[] = $value;
            }
        }
        if ($timestamp !== null && abs(time() - $timestamp) <= 300) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return;
                }
            }
        }
        throw new FMError(400, 'invalid_signature', 'Stripe signature verification failed.');
    }

    private static function recurring(string $interval): array
    {
        return match ($interval) {
            'monthly' => ['interval' => 'month', 'interval_count' => 1],
            'quarterly' => ['interval' => 'month', 'interval_count' => 3],
            'annual' => ['interval' => 'year', 'interval_count' => 1],
        };
    }

    private static function localId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1;
    }

    private static function identifier(mixed $value, string $prefix): bool
    {
        return is_string($value) && strlen($value) <= 128 && str_starts_with($value, $prefix)
            && preg_match('/^[A-Za-z0-9_]+$/D', substr($value, strlen($prefix))) === 1;
    }

    private static function stripeUrl(mixed $value, string $host): string
    {
        $url = is_string($value) ? parse_url($value) : false;
        if (!is_array($url) || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== $host
            || isset($url['port']) || isset($url['user']) || isset($url['pass']) || preg_match('/[\\\\\x00-\x20\x7F]/', $value)) {
            throw new FMError(503, 'stripe_redirect', 'Stripe did not provide a supported hosted URL.');
        }
        return $value;
    }

    private static function request(array $config, string $method, string $path, array $params = [], ?string $key = null): array
    {
        $fixture = $config['app_env'] === 'test' ? ($config['stripe']['test_transport'] ?? null) : null;
        if (is_callable($fixture)) {
            return $fixture($method, $path, $params, $key);
        }
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url = 'https://api.stripe.com/v1' . $path . ($method === 'GET' && $query !== '' ? '?' . $query : '');
        $headers = ['Authorization: Bearer ' . $config['stripe']['secret_key'], 'Stripe-Version: ' . self::VERSION];
        if ($key !== null) {
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new FMError(503, 'stripe_unavailable', 'Stripe is temporarily unavailable.');
        }
        try {
            $body = '';
            curl_setopt_array($curl, [CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 1048576) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                }]);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $query);
            }
            $success = curl_exec($curl) !== false;
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $data = $success && $status >= 200 && $status < 300 ? json_decode($body, true, 32) : null;
            if (!is_array($data)) {
                error_log('Food Monster Stripe request failed with HTTP status ' . (int)$status . '.');
                throw new FMError(503, 'stripe_unavailable', 'Stripe could not complete the request. Try again or contact billing support.');
            }
            return $data;
        } finally {
            curl_close($curl);
        }
    }
}
