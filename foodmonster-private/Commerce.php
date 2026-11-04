<?php
declare(strict_types=1);

if (!defined('FOODMONSTER_BOOTSTRAP')) {
    http_response_code(404);
    exit;
}

/** Central subscriptions and installation registry. This class never charges or provisions. */
final class FMCommerce
{
    private const HOSTING_MODES = ['cloud', 'self_hosted'];
    private const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'SGD', 'HKD', 'ZAR'];
    private const RESERVED_LABELS = [
        'www', 'www1', 'www2', 'api', 'app', 'apps', 'account', 'accounts', 'admin',
        'administrator', 'auth', 'billing', 'blog', 'cdn', 'chat', 'cloud', 'cpanel',
        'dashboard', 'db', 'dev', 'docs', 'download', 'downloads', 'email', 'ftp',
        'foodmonster', 'gelato', 'help', 'host', 'hosting', 'imap', 'internal', 'login',
        'mail', 'mailbox', 'mx', 'mysql', 'ns', 'ns1', 'ns2', 'panel', 'payment',
        'payments', 'pop', 'pop3', 'portal', 'preview', 'private', 'register', 'root',
        'security', 'server', 'shop', 'signin', 'signup', 'smtp', 'sql', 'ssh',
        'ssl', 'staging', 'static', 'status', 'store', 'support', 'system', 'test',
        'updates', 'verification', 'verify', 'webdisk', 'webmail', 'whm', 'whois',
    ];

    public static function dispatch(string $action, array $input, FMDatabase $db, array $config): ?array
    {
        return match ($action) {
            'packages.list' => ['packages' => self::publicPackages($db), 'stripe_ready' => FMStripeBilling::ready($db, $config),
                'stripe_checkout_enabled' => FMStripeBilling::checkoutEnabled($config)],
            'account.summary' => self::accountSummary($db, $config),
            'subscription.request' => self::requestSubscription($input, $db, $config),
            'stripe.checkout' => self::requestSubscription($input, $db, $config, true),
            'stripe.portal' => FMStripeBilling::portal($db, $config, $input),
            'stripe.recover' => FMStripeBilling::recover($db, $config, $input),
            'domains.check' => self::checkDomain($input, $db, $config),
            'installations.register' => self::registerInstallation($input, $db, $config),
            'installations.hosted_setup' => self::hostedSetup($input, $db, $config),
            'installations.verify' => self::verifyInstallation($input, $db, $config),
            'installations.rename' => self::renameSubdomain($input, $db, $config),
            'installations.release' => self::releaseInstallation($input, $db),
            'admin.overview' => self::adminOverview($db, $config),
            'admin.package_save' => self::savePackage($input, $db),
            'admin.package_archive' => self::archivePackage($input, $db),
            'admin.subscription_activate' => self::activateSubscription($input, $db),
            'admin.subscription_suspend' => self::suspendSubscription($input, $db),
            'admin.subdomain_add' => self::registerInstallation($input, $db, $config, true),
            'admin.installation_status' => self::installationStatus($input, $db),
            default => FMSubdomains::dispatch($action, $input, $db),
        };
    }

    private static function accountSummary(FMDatabase $db, array $config): array
    {
        $user = FMSecurity::requireUser($db);
        $subscriptions = $db->query('SELECT * FROM fm_subscriptions WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$user['id']]);
        $installations = $db->query('SELECT * FROM fm_installations WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$user['id']]);
        $business = $db->one('SELECT business_name FROM business_profiles WHERE user_id = ?', [$user['id']]);
        return [
            'user' => self::publicUser($user),
            'business' => $business ?? ['business_name' => ''],
            'packages' => self::publicPackages($db),
            'subscriptions' => FMStripeBilling::decorate($db, array_map(self::subscriptionData(...), $subscriptions)),
            'installations' => FMSubdomains::decorate($db, FMLicensing::decorate($db, FMHostedSetup::decorate($db, array_map(self::installationData(...), $installations)), $config)),
            'subdomain_transfers' => FMSubdomains::incoming($db, $user['id']),
            'stripe_ready' => FMStripeBilling::ready($db, $config),
            'hosted_setup_ready' => FMLicensingUpgrade::hostedReady($db),
            'base_domain' => self::baseDomain($config),
        ];
    }

    private static function publicPackages(FMDatabase $db): array
    {
        return array_map(fn ($row) => self::packageData($db, $row), $db->query('SELECT * FROM fm_packages WHERE published = 1 AND archived = 0 ORDER BY created_at ASC, id ASC'));
    }

    private static function requestSubscription(array $input, FMDatabase $db, array $config, bool $stripe = false): array
    {
        $user = FMSecurity::requireUser($db);
        FMSecurity::requireVerified($user);
        FMSecurity::rateLimit($db, 'subscription_request:' . $user['id'], 20, 3600);
        $packageId = self::requiredString($input, 'package_id', 1, 64);
        $interval = self::choice($input, 'billing_interval', ['monthly', 'quarterly', 'annual']);
        $mode = self::choice($input, 'hosting_mode', self::HOSTING_MODES);
        $stripeReady = FMStripeBilling::ready($db, $config);
        if ($stripe && (!$stripeReady || $mode !== 'cloud')) {
            throw new FMError(409, 'checkout_unavailable', 'Stripe checkout is available for paid Cloud packages when billing is configured.');
        }
        $row = $db->transaction(function () use ($db, $user, $packageId, $interval, $mode, $stripe, $stripeReady, $config): array {
            self::lockUser($db, $user['id']);
            if ($stripe) {
                $previous = FMStripeBilling::openCheckout($db, $user['id'], $packageId, $interval, $config);
                if ($previous !== null) {
                    return $previous;
                }
                if (!FMStripeBilling::checkoutEnabled($config)) {
                    throw new FMError(409, 'checkout_paused', 'New Stripe checkout is temporarily paused.');
                }
            }
            $package = $db->one('SELECT * FROM fm_packages WHERE id = ? AND published = 1 AND archived = 0', [$packageId]);
            if (!$package) {
                throw new FMError(404, 'package_unavailable', 'This package is no longer available.');
            }
            if (!in_array($mode, self::jsonList($package['hosting_modes']), true)) {
                throw new FMError(422, 'hosting_unavailable', 'This package does not include the selected hosting option.');
            }
            $price = self::packageData($db, $package)[$interval . '_price_cents'];
            if ($price === null) {
                throw new FMError(422, 'interval_unavailable', 'This package does not offer the selected billing interval.');
            }
            if ($stripe && $price === 0) {
                throw new FMError(422, 'checkout_unavailable', 'Use the package request for this free plan.');
            }
            if (!$stripe && $stripeReady && $mode === 'cloud' && $price > 0) {
                throw new FMError(409, 'checkout_required', 'Use Stripe checkout for this Cloud package.');
            }
            $duplicate = $db->one("SELECT id FROM fm_subscriptions WHERE user_id = ? AND package_id = ? AND hosting_mode = ?
                AND (status = 'pending_review' OR (status = 'active' AND expires_at > ?))", [$user['id'], $packageId, $mode, time()]);
            if (!$duplicate && FMLicensingUpgrade::commercialSince($db) !== null) {
                $duplicate = FMStripeBilling::ongoing($db, $user['id'], $packageId, $mode);
            }
            if ($duplicate) {
                throw new FMError(409, 'subscription_exists', 'You already have an active subscription or request for this package and hosting option.');
            }
            $id = $db->id();
            $now = time();
            $db->execute('INSERT INTO fm_subscriptions (id, user_id, package_id, package_name, status, billing_interval, hosting_mode, price_cents, currency, max_installations, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, $user['id'], $packageId, $package['name'], 'pending_review', $interval, $mode, $price, $package['currency'], (int) $package['max_installations'], $now, $now]);
            if ($stripe) {
                FMStripeBilling::reserve($db, $id, $user['email'], $config);
            }
            FMSecurity::audit($db, $user['id'], 'subscription.requested', ['subscription_id' => $id, 'package_id' => $packageId, 'hosting_mode' => $mode, 'billing_interval' => $interval]);
            return self::subscription($db, $id);
        });
        if ($stripe) {
            return FMStripeBilling::checkout($db, $config, $row);
        }
        return ['subscription' => self::subscriptionData($row), 'message' => 'Your package request is awaiting administrator review. No payment has been taken.'];
    }

    private static function checkDomain(array $input, FMDatabase $db, array $config): array
    {
        $user = FMSecurity::requireUser($db);
        FMSecurity::requireVerified($user);
        FMSecurity::rateLimit($db, 'domain_check:' . $user['id'], 90, 300);
        $label = self::cloudLabel($input, $config);
        $domain = $label . '.' . self::baseDomain($config);
        $available = $db->one('SELECT installation_id FROM fm_domain_claims WHERE domain = ?', [$domain]) === null;
        return ['available' => $available, 'subdomain' => $label, 'domain' => $domain, 'message' => $available ? 'This name is available in the Food Monster registry. It is reserved only when registration succeeds.' : 'This name is already reserved. Please choose another.'];
    }

    private static function hostedSetup(array $input, FMDatabase $db, array $config): array
    {
        $owner = FMSecurity::requireUser($db);
        FMSecurity::requireVerified($owner);
        if (!FMLicensingUpgrade::hostedReady($db)) {
            throw new FMError(503, 'upgrade_required', 'An administrator must run the hosted setup upgrade.');
        }
        FMSecurity::rateLimit($db, 'hosted_setup:' . $owner['id'], 12, 3600);
        [$profile, $hash] = FMHostedSetup::profile($input, $owner);

        return $db->transaction(static function () use ($db, $config, $owner, $input, $profile, $hash): array {
            $pending = $input['installation_id'] ?? null;
            if ($pending !== null) {
                self::lockUser($db, $owner['id']);
                $row = self::ownedInstallation($db, self::requiredString($input, 'installation_id', 32, 32), $owner);
                if ($row['hosting_mode'] !== 'cloud' || $row['status'] !== 'reserved' || $row['released_at'] !== null
                    || $db->one('SELECT installation_id FROM fm_hosted_setups WHERE installation_id = ?', [$row['id']])
                    || !FMLicensing::canClaimHosted($db, $row['id'])
                    || self::effectiveSubscriptionStatus(self::subscription($db, $row['subscription_id'])) !== 'active') {
                    throw new FMError(409, 'setup_unavailable', 'Choose an unused reservation on an active Cloud subscription.');
                }
                FMSubdomains::assertNoTransfer($db, $row);
                FMSubdomains::assertCurrent($db, $row);
                $db->execute('UPDATE fm_installations SET business_name = ?, updated_at = ? WHERE id = ?',
                    [$profile['restaurant_name'], time(), $row['id']]);
                $installation = self::installationData(self::installation($db, $row['id']));
            } else {
                $result = self::registerInstallation(['hosting_mode' => 'cloud', 'business_name' => $profile['restaurant_name']] + $input, $db, $config);
                $installation = $result['installation'];
            }
            FMHostedSetup::queue($db, $installation['id'], $profile, $hash);
            FMSecurity::audit($db, $owner['id'], 'installation.hosted_setup_submitted', ['installation_id' => $installation['id']]);
            return ['installation' => $installation, 'message' => 'Setup details saved. Your Gelato site is queued for provisioning.'];
        });
    }

    private static function registerInstallation(array $input, FMDatabase $db, array $config, bool $adminCloud = false): array
    {
        $actor = $adminCloud ? FMSecurity::requireAdmin($db) : FMSecurity::requireUser($db);
        FMSecurity::requireVerified($actor);
        FMSecurity::rateLimit($db, 'installation_register:' . $actor['id'], 20, 3600);
        $subscriptionId = $adminCloud ? self::requiredString($input, 'subscription_id', 32, 32) : null;
        $ownerId = $subscriptionId === null ? $actor['id'] : self::subscription($db, $subscriptionId)['user_id'];
        $name = self::requiredString($input, 'business_name', 2, 160);
        $mode = $adminCloud ? 'cloud' : self::choice($input, 'hosting_mode', self::HOSTING_MODES);
        $label = $mode === 'cloud' ? self::cloudLabel($input, $config) : null;
        $domain = $label !== null ? $label . '.' . self::baseDomain($config) : self::customerDomain($input, $config);
        try {
            $row = $db->transaction(function () use ($db, $actor, $ownerId, $subscriptionId, $name, $mode, $label, $domain): array {
                self::lockUser($db, $ownerId);
                if ($db->one('SELECT installation_id FROM fm_domain_claims WHERE domain = ?', [$domain])) {
                    throw new FMError(409, 'domain_unavailable', 'This domain is already registered.');
                }
                $subscription = self::selectSubscription($db, $ownerId, $mode, $subscriptionId);
                $id = $db->id();
                $now = time();
                $status = $mode === 'cloud' ? 'reserved' : 'verification_pending';
                $token = bin2hex(random_bytes(24));
                $db->execute('INSERT INTO fm_installations (id, user_id, subscription_id, business_name, hosting_mode, subdomain, domain, status, verification_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, $ownerId, $subscription['id'], $name, $mode, $label, $domain, $status, $token, $now, $now]);
                // The unique claim is the final authority. Availability checks are advisory.
                $db->execute('INSERT INTO fm_domain_claims (domain, installation_id, created_at) VALUES (?, ?, ?)', [$domain, $id, $now]);
                FMSecurity::audit($db, $actor['id'], 'installation.registered', ['installation_id' => $id, 'owner_id' => $ownerId, 'subscription_id' => $subscription['id'], 'domain' => $domain, 'hosting_mode' => $mode]);
                return self::installation($db, $id);
            });
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new FMError(409, 'domain_unavailable', 'This name was reserved by another registration. Please choose another.');
            }
            throw $error;
        }
        $message = match (true) {
            $adminCloud => 'Subdomain reserved for the customer. Continue with hosting, TLS, and Gelato setup.',
            $mode === 'cloud' => 'Your subdomain is reserved. Hosting, TLS, and Gelato installation still require administrator setup.',
            default => 'Your installation is registered. Add the DNS TXT record, then verify your domain.',
        };
        return ['installation' => FMSubdomains::decorate($db, [self::installationData($row)])[0], 'message' => $message];
    }

    private static function verifyInstallation(array $input, FMDatabase $db, array $config): array
    {
        $user = FMSecurity::requireUser($db);
        FMSecurity::requireVerified($user);
        $id = self::requiredString($input, 'installation_id', 1, 64);
        $row = self::ownedInstallation($db, $id, $user, true);
        if ($row['released_at'] !== null) {
            throw new FMError(409, 'installation_released', 'Released installations cannot be verified.');
        }
        FMSecurity::rateLimit($db, 'dns_verify:' . $user['id'], 20, 300);
        $record = '_foodmonster.' . $row['domain'];
        $expected = 'foodmonster-verification=' . $row['verification_token'];
        $matches = false;
        foreach (self::dnsRecords($record, $config) as $value) {
            if (is_string($value) && hash_equals($expected, $value)) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            return ['installation' => self::installationData($row), 'verified' => false, 'message' => 'The matching TXT record is not visible yet. Check the record name and value, allow DNS time to update, then try again.'];
        }
        $ownerId = $row['user_id'];
        $row = $db->transaction(function () use ($db, $id, $user, $ownerId, $row): array {
            self::lockUser($db, $ownerId);
            $current = self::ownedInstallation($db, $id, $user, true);
            if ($current['released_at'] !== null) {
                throw new FMError(409, 'installation_released', 'Released installations cannot be verified.');
            }
            FMSubdomains::assertNoTransfer($db, $current);
            if ($current['domain'] !== $row['domain'] || !hash_equals($current['verification_token'], $row['verification_token'])) {
                throw new FMError(409, 'domain_changed', 'The domain or verification challenge changed. Verify the current TXT record.');
            }
            $status = in_array($current['status'], ['reserved', 'verification_pending'], true) ? 'verified' : $current['status'];
            $now = time();
            $db->execute('UPDATE fm_installations SET verified_at = ?, status = ?, updated_at = ? WHERE id = ?', [$now, $status, $now, $id]);
            FMSecurity::audit($db, $user['id'], 'installation.domain_verified', ['installation_id' => $id, 'domain' => $current['domain'], 'method' => 'dns_txt']);
            return self::installation($db, $id);
        });
        return ['installation' => self::installationData($row), 'verified' => true, 'message' => 'DNS ownership is verified. Hosting and Gelato deployment are separate setup steps.'];
    }

    private static function renameSubdomain(array $input, FMDatabase $db, array $config): array
    {
        $user = FMSecurity::requireUser($db);
        FMSecurity::requireVerified($user);
        FMSecurity::rateLimit($db, 'domain_rename:' . $user['id'], 20, 3600);
        $id = self::requiredString($input, 'installation_id', 1, 64);
        $label = self::cloudLabel($input, $config);
        $domain = $label . '.' . self::baseDomain($config);
        try {
            $row = $db->transaction(static function () use ($db, $user, $id, $label, $domain): array {
                self::lockUser($db, $user['id']);
                $row = self::ownedInstallation($db, $id, $user);
                FMSubdomains::assertNoTransfer($db, $row);
                FMSubdomains::assertCurrent($db, $row);
                if (FMLicensingUpgrade::hostedReady($db) && $db->one('SELECT installation_id FROM fm_hosted_setups WHERE installation_id = ?', [$row['id']])) {
                    throw new FMError(409, 'setup_managed', 'This installation is managed by hosted provisioning.');
                }
                if ($row['hosting_mode'] !== 'cloud' || !self::installationData($row)['can_release']) {
                    throw new FMError(409, 'rename_unavailable', 'Only an unused cloud reservation can be renamed.');
                }
                if ($row['domain'] === $domain) {
                    return $row;
                }
                $now = time();
                if ($db->execute('UPDATE fm_domain_claims SET domain = ?, created_at = ? WHERE installation_id = ?',
                    [$domain, $now, $id]) !== 1) {
                    throw new FMError(409, 'claim_missing', 'The existing reservation needs administrator review.');
                }
                $db->execute("UPDATE fm_installations SET subdomain = ?, domain = ?, status = 'reserved', verification_token = ?, verified_at = NULL, updated_at = ? WHERE id = ?",
                    [$label, $domain, bin2hex(random_bytes(24)), $now, $id]);
                FMSecurity::audit($db, $user['id'], 'installation.domain_renamed', ['installation_id' => $id, 'domain' => $domain]);
                return self::installation($db, $id);
            });
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new FMError(409, 'domain_unavailable', 'This name is already registered.');
            }
            throw $error;
        }
        return ['installation' => self::installationData($row)];
    }

    private static function releaseInstallation(array $input, FMDatabase $db): array
    {
        $user = FMSecurity::requireUser($db);
        FMSecurity::requireVerified($user);
        $id = self::requiredString($input, 'installation_id', 1, 64);
        $row = $db->transaction(function () use ($db, $user, $id): array {
            self::lockUser($db, $user['id']);
            $row = self::ownedInstallation($db, $id, $user);
            FMSubdomains::assertNoTransfer($db, $row);
            if (FMLicensingUpgrade::hostedReady($db) && $db->one('SELECT installation_id FROM fm_hosted_setups WHERE installation_id = ?', [$row['id']])) {
                throw new FMError(409, 'setup_managed', 'This installation is managed by hosted provisioning.');
            }
            if ($row['released_at'] !== null) {
                return $row;
            }
            if ($row['deployment_started_at'] !== null || $row['activated_at'] !== null || !in_array($row['status'], ['reserved', 'verification_pending', 'verified', 'failed'], true)) {
                throw new FMError(409, 'release_unavailable', 'This installation has entered deployment. Contact the administrator to coordinate removal safely.');
            }
            $now = time();
            $db->execute('DELETE FROM fm_domain_claims WHERE installation_id = ?', [$id]);
            $db->execute('UPDATE fm_installations SET status = ?, released_at = ?, updated_at = ? WHERE id = ?', ['released', $now, $now, $id]);
            FMSecurity::audit($db, $user['id'], 'installation.released', ['installation_id' => $id, 'domain' => $row['domain']]);
            return self::installation($db, $id);
        });
        return ['installation' => self::installationData($row), 'message' => 'The unused registration has been released. Its history is retained.'];
    }

    private static function adminOverview(FMDatabase $db, array $config): array
    {
        FMSecurity::requireAdmin($db);
        $customers = $db->query('SELECT u.id, u.name, u.email, u.email_verified_at, u.role, u.created_at, b.business_name FROM users u LEFT JOIN business_profiles b ON b.user_id = u.id ORDER BY u.created_at DESC, u.id DESC');
        $cpanel = FMHostingSettings::summary($db);
        $hosting = !empty($cpanel['tested_at']) && !empty($config['hosting']['tenant_root']) && !empty($config['hosting']['private_root']) && !empty($config['hosting']['account_home']);
        $stripe = FMStripeBilling::ready($db, $config);
        return [
            'packages' => array_map(fn ($row) => self::packageData($db, $row), $db->query('SELECT * FROM fm_packages ORDER BY created_at DESC, id DESC')),
            'subscriptions' => FMStripeBilling::decorate($db, array_map(self::adminSubscriptionData(...), $db->query('SELECT s.*, u.name AS customer_name, u.email AS customer_email FROM fm_subscriptions s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC, s.id DESC'))),
            'installations' => FMSubdomains::decorate($db, FMLicensing::decorate($db, FMHostedSetup::decorate($db, array_map(self::adminInstallationData(...), $db->query('SELECT i.*, u.name AS customer_name, u.email AS customer_email FROM fm_installations i JOIN users u ON u.id = i.user_id ORDER BY i.created_at DESC, i.id DESC'))), $config)),
            'licensing_ready' => FMLicensingUpgrade::ready($db),
            'cpanel' => $cpanel,
            'releases' => FMLicensing::registry($db),
            'customers' => array_map(static fn (array $row): array => self::publicUser($row) + ['business_name' => $row['business_name'] ?? '', 'created_at' => (int) $row['created_at']], $customers),
            'configuration' => [
                'base_domain' => self::baseDomain($config),
                'subscriptions' => $stripe ? 'stripe_and_manual' : 'manual_review',
                'payments_configured' => $stripe,
                'hosting_configured' => $hosting,
                'hosting_mode' => $hosting ? 'cpanel' : 'manual',
                'hosted_release_version' => $config['hosting']['release_version'] ?? null,
                'gelato_distribution_configured' => FMLicensingUpgrade::ready($db),
                'domain_verification' => 'dns_txt',
                'dns_lookup_available' => function_exists('dns_get_record'),
                'message' => $stripe ? 'Stripe manages paid Cloud plans. Hosted setup runs through the configured cPanel worker.' : 'Package activation is administrator-managed. Hosted setup runs through the configured cPanel worker.',
            ],
        ];
    }

    private static function savePackage(array $input, FMDatabase $db): array
    {
        $admin = FMSecurity::requireAdmin($db);
        $name = self::requiredString($input, 'name', 2, 120);
        $description = self::optionalString($input, 'description', 3000);
        $monthly = self::money($input, 'monthly_price_cents');
        $quarterly = self::money($input, 'quarterly_price_cents');
        $quarterlySupplied = array_key_exists('quarterly_price_cents', $input);
        $commercial = FMLicensingUpgrade::commercialSince($db) !== null;
        if ($quarterly !== null && !$commercial) {
            throw new FMError(503, 'upgrade_required', 'Run the database upgrade to offer quarterly prices.');
        }
        $annual = self::money($input, 'annual_price_cents');
        $currency = strtoupper(self::requiredString($input, 'currency', 3, 3));
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new FMError(422, 'validation_error', 'Choose a supported currency. This catalog uses two decimal places for prices.', ['currency' => 'Supported currencies: ' . implode(', ', self::CURRENCIES) . '.']);
        }
        $modes = $input['hosting_modes'] ?? null;
        if (!is_array($modes) || !array_is_list($modes) || count($modes) < 1 || count($modes) > 2) {
            throw new FMError(422, 'validation_error', 'Choose at least one hosting option.', ['hosting_modes' => 'Choose cloud, self_hosted, or both.']);
        }
        foreach ($modes as $mode) {
            if (!is_string($mode) || !in_array($mode, self::HOSTING_MODES, true)) {
                throw new FMError(422, 'validation_error', 'The package includes an unsupported hosting option.');
            }
        }
        $modes = array_values(array_unique($modes));
        $max = $input['max_installations'] ?? null;
        if (!is_int($max) || $max < 1 || $max > 10000) {
            throw new FMError(422, 'validation_error', 'Installation limits must be a whole number from 1 to 10,000.', ['max_installations' => 'Enter a whole number from 1 to 10,000.']);
        }
        $features = $input['features'] ?? [];
        if (!is_array($features) || !array_is_list($features) || count($features) > 30) {
            throw new FMError(422, 'validation_error', 'Provide up to 30 package features.');
        }
        $cleanFeatures = [];
        foreach ($features as $feature) {
            if (!is_string($feature) || strlen(trim($feature)) < 1 || strlen(trim($feature)) > 200 || preg_match('/[\x00-\x1F\x7F]/', $feature)) {
                throw new FMError(422, 'validation_error', 'Each feature must be between 1 and 200 characters on one line.');
            }
            $cleanFeatures[] = trim($feature);
        }
        $published = $input['published'] ?? false;
        if (!is_bool($published)) {
            throw new FMError(422, 'validation_error', 'Published must be true or false.');
        }
        $id = isset($input['id']) && $input['id'] !== '' ? self::requiredString($input, 'id', 1, 64) : null;
        $row = $db->transaction(function () use ($db, $admin, $id, $name, $description, $monthly, $quarterly, $quarterlySupplied, $annual, $currency, $modes, $max, $cleanFeatures, $published, $commercial): array {
            $now = time();
            if ($id !== null) {
                $existing = $db->one('SELECT * FROM fm_packages WHERE id = ?' . ($db->driver === 'mysql' ? ' FOR UPDATE' : ''), [$id]);
                if (!$existing) {
                    throw new FMError(404, 'package_missing', 'The package was not found.');
                }
                if ((bool) $existing['archived']) {
                    throw new FMError(409, 'package_archived', 'Archived packages are retained for subscription history. Create a new package to offer it again.');
                }
                if (!$quarterlySupplied) {
                    $quarterly = self::packageData($db, $existing)['quarterly_price_cents'];
                }
                $db->execute('UPDATE fm_packages SET name = ?, description = ?, monthly_price_cents = ?, annual_price_cents = ?, currency = ?, hosting_modes = ?, max_installations = ?, features = ?, published = ?, updated_at = ? WHERE id = ?', [$name, $description, $monthly, $annual, $currency, self::json($modes), $max, self::json($cleanFeatures), (int) $published, $now, $id]);
            } else {
                $id = $db->id();
                $db->execute('INSERT INTO fm_packages (id, name, description, monthly_price_cents, annual_price_cents, currency, hosting_modes, max_installations, features, published, archived, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, $name, $description, $monthly, $annual, $currency, self::json($modes), $max, self::json($cleanFeatures), (int) $published, 0, $now, $now]);
            }
            if ($published && $monthly === null && $quarterly === null && $annual === null) {
                throw new FMError(422, 'validation_error', 'Set at least one billing interval price before publishing.');
            }
            if ($commercial) {
                if ($quarterly === null) {
                    $db->execute('DELETE FROM fm_quarterly_prices WHERE package_id = ?', [$id]);
                } else {
                    $sql = $db->driver === 'mysql' ? 'ON DUPLICATE KEY UPDATE price_cents = VALUES(price_cents)' : 'ON CONFLICT(package_id) DO UPDATE SET price_cents = excluded.price_cents';
                    $db->execute('INSERT INTO fm_quarterly_prices (package_id, price_cents) VALUES (?, ?) ' . $sql, [$id, $quarterly]);
                }
            }
            FMSecurity::audit($db, $admin['id'], 'package.saved', ['package_id' => $id, 'published' => $published]);
            return $db->one('SELECT * FROM fm_packages WHERE id = ?', [$id]);
        });
        return ['package' => self::packageData($db, $row), 'message' => $published ? 'Package published. Existing subscription terms remain unchanged.' : 'Package saved as a draft.'];
    }

    private static function archivePackage(array $input, FMDatabase $db): array
    {
        $admin = FMSecurity::requireAdmin($db);
        $id = self::requiredString($input, 'package_id', 1, 64);
        $row = $db->transaction(function () use ($db, $admin, $id): array {
            $existing = $db->one('SELECT * FROM fm_packages WHERE id = ?', [$id]);
            if (!$existing) {
                throw new FMError(404, 'package_missing', 'The package was not found.');
            }
            $db->execute('UPDATE fm_packages SET archived = 1, published = 0, updated_at = ? WHERE id = ?', [time(), $id]);
            FMSecurity::audit($db, $admin['id'], 'package.archived', ['package_id' => $id]);
            return $db->one('SELECT * FROM fm_packages WHERE id = ?', [$id]);
        });
        return ['package' => self::packageData($db, $row), 'message' => 'Package archived. Existing subscription terms are retained.'];
    }

    private static function activateSubscription(array $input, FMDatabase $db): array
    {
        $admin = FMSecurity::requireAdmin($db);
        $id = self::requiredString($input, 'subscription_id', 1, 64);
        $reference = self::requiredString($input, 'reference', 3, 200);
        $expires = self::expiry($input);
        $ownerId = self::subscription($db, $id)['user_id'];
        $row = $db->transaction(function () use ($db, $admin, $id, $reference, $expires, $ownerId): array {
            self::lockUser($db, $ownerId);
            $row = self::subscription($db, $id);
            if (FMStripeBilling::isStripe($db, $id)) {
                throw new FMError(409, 'stripe_managed', 'Manage this subscription and its billing in Stripe.');
            }
            $customer = $db->one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
            FMSecurity::requireVerified($customer);
            if (!in_array($row['status'], ['pending_review', 'active', 'suspended'], true)) {
                throw new FMError(409, 'subscription_state', 'This subscription cannot be activated.');
            }
            $now = time();
            $db->execute('UPDATE fm_subscriptions SET status = ?, expires_at = ?, activation_reference = ?, suspension_reason = NULL, activated_at = ?, updated_at = ? WHERE id = ?', ['active', $expires, $reference, $now, $now, $id]);
            FMSecurity::audit($db, $admin['id'], 'subscription.activated_manually', ['subscription_id' => $id, 'customer_id' => $row['user_id'], 'previous_status' => self::effectiveSubscriptionStatus($row), 'expires_at' => $expires, 'reference' => $reference]);
            return self::subscription($db, $id);
        });
        return ['subscription' => self::subscriptionData($row), 'message' => 'Subscription activated with the recorded expiry and reference. No payment was processed by this action.'];
    }

    private static function suspendSubscription(array $input, FMDatabase $db): array
    {
        $admin = FMSecurity::requireAdmin($db);
        $id = self::requiredString($input, 'subscription_id', 1, 64);
        $reason = self::requiredString($input, 'reason', 3, 1000);
        $ownerId = self::subscription($db, $id)['user_id'];
        $row = $db->transaction(function () use ($db, $admin, $id, $reason, $ownerId): array {
            self::lockUser($db, $ownerId);
            if (FMStripeBilling::isStripe($db, $id)) {
                throw new FMError(409, 'stripe_managed', 'Manage this subscription and its billing in Stripe.');
            }
            $row = self::subscription($db, $id);
            $db->execute('UPDATE fm_subscriptions SET status = ?, suspension_reason = ?, updated_at = ? WHERE id = ?', ['suspended', $reason, time(), $id]);
            FMSecurity::audit($db, $admin['id'], 'subscription.suspended', ['subscription_id' => $id, 'customer_id' => $row['user_id'], 'previous_status' => self::effectiveSubscriptionStatus($row), 'reason' => $reason]);
            return self::subscription($db, $id);
        });
        return ['subscription' => self::subscriptionData($row), 'message' => 'Subscription entitlement suspended. Any running hosting service must be handled separately by the administrator.'];
    }

    private static function installationStatus(array $input, FMDatabase $db): array
    {
        $admin = FMSecurity::requireAdmin($db);
        $id = self::requiredString($input, 'installation_id', 1, 64);
        $status = self::choice($input, 'status', ['verification_pending', 'provisioning', 'active', 'suspended', 'failed']);
        $note = self::requiredString($input, 'note', 10, 2000);
        $ownerId = self::installation($db, $id)['user_id'];
        $row = $db->transaction(function () use ($db, $admin, $id, $status, $note, $ownerId): array {
            self::lockUser($db, $ownerId);
            $row = self::installation($db, $id);
            if ($row['user_id'] !== $ownerId) {
                throw new FMError(409, 'owner_changed', 'Refresh the installation after its transfer.');
            }
            FMSubdomains::assertNoTransfer($db, $row);
            if (FMLicensingUpgrade::hostedReady($db) && $db->one('SELECT installation_id FROM fm_hosted_setups WHERE installation_id = ?', [$row['id']])) {
                throw new FMError(409, 'setup_managed', 'This installation is managed by hosted provisioning.');
            }
            if ($row['released_at'] !== null) {
                throw new FMError(409, 'installation_released', 'A released registration cannot be changed.');
            }
            $allowed = [
                'reserved' => ['verification_pending', 'provisioning', 'suspended', 'failed'],
                'verification_pending' => ['verification_pending', 'provisioning', 'suspended', 'failed'],
                'verified' => ['provisioning', 'active', 'suspended', 'failed'],
                'provisioning' => ['provisioning', 'active', 'suspended', 'failed'],
                'active' => ['active', 'suspended', 'failed'],
                'suspended' => ['provisioning', 'active', 'suspended', 'failed'],
                'failed' => ['verification_pending', 'provisioning', 'active', 'suspended', 'failed'],
            ];
            if (!in_array($status, $allowed[$row['status']] ?? [], true)) {
                throw new FMError(409, 'installation_transition', 'This lifecycle transition is not available.');
            }
            if (in_array($status, ['provisioning', 'active'], true)) {
                FMSubdomains::assertCurrent($db, $row);
                $subscription = self::subscription($db, $row['subscription_id']);
                if (self::effectiveSubscriptionStatus($subscription) !== 'active') {
                    throw new FMError(409, 'subscription_inactive', 'An active, unexpired subscription is required before deployment.');
                }
            }
            if ($status === 'active' && $row['verified_at'] === null) {
                throw new FMError(409, 'domain_unverified', 'Verify the DNS TXT record before marking this installation active.');
            }
            $now = time();
            $deploymentStarted = in_array($status, ['provisioning', 'active'], true) ? ($row['deployment_started_at'] ?? $now) : $row['deployment_started_at'];
            $activated = $status === 'active' ? ($row['activated_at'] ?? $now) : $row['activated_at'];
            $verificationReset = $status === 'verification_pending' && $row['verified_at'] !== null;
            $verifiedAt = $verificationReset ? null : $row['verified_at'];
            $verificationToken = $verificationReset ? bin2hex(random_bytes(24)) : $row['verification_token'];
            $db->execute('UPDATE fm_installations SET status = ?, admin_note = ?, deployment_started_at = ?, activated_at = ?, verified_at = ?, verification_token = ?, updated_at = ? WHERE id = ?', [$status, $note, $deploymentStarted, $activated, $verifiedAt, $verificationToken, $now, $id]);
            FMSecurity::audit($db, $admin['id'], 'installation.lifecycle_recorded', ['installation_id' => $id, 'previous_status' => $row['status'], 'status' => $status, 'note' => $note, 'mode' => 'manual_record', 'verification_reset' => $verificationReset]);
            return self::installation($db, $id);
        });
        return ['installation' => self::installationData($row), 'message' => 'Installation status recorded. This action does not configure DNS, TLS, hosting, or the Gelato script.'];
    }

    private static function packageData(FMDatabase $db, array $row): array
    {
        $quarterly = FMLicensingUpgrade::commercialSince($db) !== null
            ? $db->one('SELECT price_cents FROM fm_quarterly_prices WHERE package_id = ?', [$row['id']])
            : null;
        return ['id' => $row['id'], 'name' => $row['name'], 'description' => $row['description'], 'monthly_price_cents' => $row['monthly_price_cents'] === null ? null : (int) $row['monthly_price_cents'], 'quarterly_price_cents' => $quarterly ? (int)$quarterly['price_cents'] : null, 'annual_price_cents' => $row['annual_price_cents'] === null ? null : (int) $row['annual_price_cents'], 'currency' => $row['currency'], 'hosting_modes' => self::jsonList($row['hosting_modes']), 'max_installations' => (int) $row['max_installations'], 'features' => self::jsonList($row['features']), 'published' => (bool) $row['published'], 'archived' => (bool) $row['archived'], 'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at']];
    }

    private static function subscriptionData(array $row): array
    {
        return ['id' => $row['id'], 'package_id' => $row['package_id'], 'package_name' => $row['package_name'], 'status' => self::effectiveSubscriptionStatus($row), 'billing_interval' => $row['billing_interval'], 'hosting_mode' => $row['hosting_mode'], 'price_cents' => (int) $row['price_cents'], 'currency' => $row['currency'], 'max_installations' => (int) $row['max_installations'], 'expires_at' => $row['expires_at'] === null ? null : (int) $row['expires_at'], 'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at']];
    }

    private static function adminSubscriptionData(array $row): array
    {
        return self::subscriptionData($row) + ['user_id' => $row['user_id'], 'customer_name' => $row['customer_name'], 'customer_email' => $row['customer_email'], 'activation_reference' => $row['activation_reference'], 'suspension_reason' => $row['suspension_reason']];
    }

    private static function installationData(array $row): array
    {
        return ['id' => $row['id'], 'subscription_id' => $row['subscription_id'], 'business_name' => $row['business_name'], 'hosting_mode' => $row['hosting_mode'], 'subdomain' => $row['subdomain'], 'domain' => $row['domain'], 'status' => $row['status'], 'verified_at' => $row['verified_at'] === null ? null : (int) $row['verified_at'], 'verification_record' => '_foodmonster.' . $row['domain'], 'verification_value' => 'foodmonster-verification=' . $row['verification_token'], 'deployment_started_at' => $row['deployment_started_at'] === null ? null : (int) $row['deployment_started_at'], 'activated_at' => $row['activated_at'] === null ? null : (int) $row['activated_at'], 'released_at' => $row['released_at'] === null ? null : (int) $row['released_at'], 'can_release' => $row['released_at'] === null && $row['deployment_started_at'] === null && $row['activated_at'] === null && in_array($row['status'], ['reserved', 'verification_pending', 'verified', 'failed'], true), 'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at']];
    }

    private static function adminInstallationData(array $row): array
    {
        return self::installationData($row) + ['user_id' => $row['user_id'], 'customer_name' => $row['customer_name'], 'customer_email' => $row['customer_email'], 'admin_note' => $row['admin_note']];
    }

    private static function publicUser(array $row): array
    {
        return ['id' => $row['id'], 'name' => $row['name'], 'email' => $row['email'], 'email_verified' => !empty($row['email_verified_at']), 'role' => $row['role']];
    }

    private static function effectiveSubscriptionStatus(array $row): string
    {
        return $row['status'] === 'active' && ($row['expires_at'] === null || (int) $row['expires_at'] <= time()) ? 'expired' : $row['status'];
    }

    private static function subscription(FMDatabase $db, string $id): array
    {
        $row = $db->one('SELECT * FROM fm_subscriptions WHERE id = ?', [$id]);
        if (!$row) {
            throw new FMError(404, 'subscription_missing', 'The subscription was not found.');
        }
        return $row;
    }

    private static function installation(FMDatabase $db, string $id): array
    {
        $row = $db->one('SELECT * FROM fm_installations WHERE id = ?', [$id]);
        if (!$row) {
            throw new FMError(404, 'installation_missing', 'The installation was not found.');
        }
        return $row;
    }

    private static function ownedInstallation(FMDatabase $db, string $id, array $user, bool $allowAdmin = false): array
    {
        $row = $allowAdmin && $user['role'] === 'admin'
            ? $db->one('SELECT * FROM fm_installations WHERE id = ?', [$id])
            : $db->one('SELECT * FROM fm_installations WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        if (!$row) {
            throw new FMError(404, 'installation_missing', 'The installation was not found.');
        }
        return $row;
    }

    public static function lockUser(FMDatabase $db, string $id): void
    {
        // SQLite transactions use BEGIN IMMEDIATE in FMDatabase. MySQL serializes per owner.
        if ($db->driver === 'mysql') {
            $db->one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id]);
        }
    }

    public static function selectSubscription(FMDatabase $db, string $owner, string $mode, ?string $id = null, ?array $existing = null): array
    {
        $sql = "SELECT * FROM fm_subscriptions WHERE user_id = ? AND hosting_mode = ? AND status = 'active' AND expires_at > ?";
        $params = [$owner, $mode, time()];
        if ($id !== null) {
            $sql .= ' AND id = ?';
            $params[] = $id;
        }
        $sql .= ' ORDER BY CASE WHEN id = ? THEN 0 ELSE 1 END, expires_at, created_at, id';
        $params[] = $existing['subscription_id'] ?? '';
        $subscriptions = $db->query($sql, $params);
        if (!$subscriptions) {
            throw new FMError(403, 'subscription_required', 'An active subscription for this hosting option is required.');
        }
        foreach ($subscriptions as $subscription) {
            $count = $db->one('SELECT COUNT(*) AS total FROM fm_installations WHERE subscription_id = ? AND released_at IS NULL AND id <> ?',
                [$subscription['id'], $existing['id'] ?? '']);
            if ((int)$count['total'] < (int)$subscription['max_installations']) {
                return $subscription;
            }
        }
        throw new FMError(409, 'installation_limit', 'Your active subscription installation limit has been reached.');
    }

    private static function baseDomain(array $config): string
    {
        $domain = strtolower(trim((string) ($config['base_domain'] ?? 'foodmonster.xyz')));
        if (strlen($domain) > 199 || !self::validDomain($domain, ($config['app_env'] ?? '') === 'test')) {
            throw new FMError(503, 'configuration_error', 'The platform domain needs administrator configuration.');
        }
        return $domain;
    }

    private static function cloudLabel(array $input, array $config): string
    {
        $label = strtolower(self::requiredString($input, 'subdomain', 3, 40));
        if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $label)) {
            throw new FMError(422, 'invalid_subdomain', 'Use 3–40 lowercase letters, numbers, or single hyphens, starting with a letter.', ['subdomain' => 'Start with a letter; no spaces, dots, leading/trailing hyphens, or repeated hyphens.']);
        }
        $extra = $config['reserved_subdomains'] ?? [];
        if (!is_array($extra)) {
            $extra = [];
        }
        $denylist = array_merge(self::RESERVED_LABELS, array_map(static fn ($value): string => strtolower((string) $value), $extra));
        if (in_array($label, $denylist, true) || preg_match('/^(?:ns|mail|smtp|admin|api|www)[0-9]+$/D', $label)) {
            throw new FMError(422, 'reserved_subdomain', 'This name is reserved for platform services. Please choose another.', ['subdomain' => 'This name is reserved.']);
        }
        return $label;
    }

    private static function customerDomain(array $input, array $config): string
    {
        // Leave room within the DNS 253-character hostname limit for _foodmonster.
        $domain = strtolower(self::requiredString($input, 'domain', 3, 240));
        $test = ($config['app_env'] ?? '') === 'test';
        if (!self::validDomain($domain, $test)) {
            throw new FMError(422, 'invalid_domain', 'Enter a public DNS hostname without a protocol, port, path, or wildcard.', ['domain' => 'For example, order.yourrestaurant.com.']);
        }
        $base = self::baseDomain($config);
        if ($domain === $base || str_ends_with($domain, '.' . $base)) {
            throw new FMError(422, 'platform_domain', 'Food Monster subdomains must use the cloud hosting registration option.');
        }
        return $domain;
    }

    private static function validDomain(string $domain, bool $allowTest): bool
    {
        if (strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP) || !str_contains($domain, '.')) {
            return false;
        }
        $parts = explode('.', $domain);
        foreach ($parts as $part) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $part)) {
                return false;
            }
        }
        $tld = end($parts);
        if (!preg_match('/^[a-z]{2,63}$/D', $tld)) {
            return false;
        }
        if (in_array($tld, ['localhost', 'local', 'internal', 'invalid', 'example', 'onion'], true) || (!$allowTest && $tld === 'test')) {
            return false;
        }
        return !in_array($domain, ['example.com', 'example.net', 'example.org'], true) && !str_ends_with($domain, '.example.com') && !str_ends_with($domain, '.example.net') && !str_ends_with($domain, '.example.org');
    }

    private static function dnsRecords(string $record, array $config): array
    {
        // Only the local integration test configuration can replace DNS resolution.
        if (($config['app_env'] ?? '') === 'test' && isset($config['dns_txt_resolver']) && is_callable($config['dns_txt_resolver'])) {
            $records = ($config['dns_txt_resolver'])($record);
            if (!is_array($records)) {
                throw new FMError(503, 'dns_unavailable', 'DNS lookup is temporarily unavailable. Try again later.');
            }
            return $records;
        }
        if (!function_exists('dns_get_record')) {
            throw new FMError(503, 'dns_unavailable', 'The server DNS lookup extension needs administrator configuration.');
        }
        $records = @dns_get_record($record, DNS_TXT);
        if ($records === false) {
            throw new FMError(503, 'dns_unavailable', 'DNS lookup is temporarily unavailable. Try again later.');
        }
        $values = [];
        foreach ($records as $row) {
            if (isset($row['entries']) && is_array($row['entries'])) {
                $values[] = implode('', $row['entries']);
            } elseif (isset($row['txt']) && is_string($row['txt'])) {
                $values[] = $row['txt'];
            }
        }
        return $values;
    }

    private static function requiredString(array $input, string $key, int $min, int $max): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            throw new FMError(422, 'validation_error', 'Check the highlighted fields.', [$key => 'This field is required.']);
        }
        $value = trim($value);
        if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new FMError(422, 'validation_error', 'Check the highlighted fields.', [$key => 'Use between ' . $min . ' and ' . $max . ' characters.']);
        }
        return $value;
    }

    private static function optionalString(array $input, string $key, int $max): string
    {
        if (!isset($input[$key]) || $input[$key] === '') {
            return '';
        }
        return self::requiredString($input, $key, 0, $max);
    }

    private static function choice(array $input, string $key, array $allowed): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new FMError(422, 'validation_error', 'Choose a valid ' . str_replace('_', ' ', $key) . '.', [$key => 'Choose one of: ' . implode(', ', $allowed) . '.']);
        }
        return $value;
    }

    private static function money(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0 || $value > 1000000000) {
            throw new FMError(422, 'validation_error', 'Enter prices as nonnegative whole minor-currency units, or leave the interval disabled.', [$key => 'Use an integer from 0 to 1,000,000,000, or null.']);
        }
        return $value;
    }

    private static function expiry(array $input): int
    {
        $value = $input['expires_at'] ?? null;
        if (is_int($value)) {
            $timestamp = $value;
        } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $timestamp = strtotime($value);
        } else {
            $timestamp = false;
        }
        if ($timestamp === false || $timestamp <= time() || $timestamp > time() + 366 * 86400 * 10) {
            throw new FMError(422, 'validation_error', 'Choose a future expiry date within the next ten years.', ['expires_at' => 'Use a future Unix timestamp or an ISO 8601 timestamp with its timezone.']);
        }
        return $timestamp;
    }

    private static function jsonList(string $value): array
    {
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
