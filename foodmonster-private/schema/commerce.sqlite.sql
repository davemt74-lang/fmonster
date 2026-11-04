CREATE TABLE IF NOT EXISTS fm_packages (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NOT NULL,
    monthly_price_cents INTEGER NULL,
    annual_price_cents INTEGER NULL,
    currency TEXT NOT NULL,
    hosting_modes TEXT NOT NULL,
    max_installations INTEGER NOT NULL,
    features TEXT NOT NULL,
    published INTEGER NOT NULL DEFAULT 0,
    archived INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS fm_packages_public ON fm_packages(published, archived);

CREATE TABLE IF NOT EXISTS fm_subscriptions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id),
    package_id TEXT NOT NULL REFERENCES fm_packages(id),
    package_name TEXT NOT NULL,
    status TEXT NOT NULL,
    billing_interval TEXT NOT NULL,
    hosting_mode TEXT NOT NULL,
    price_cents INTEGER NOT NULL,
    currency TEXT NOT NULL,
    max_installations INTEGER NOT NULL,
    expires_at INTEGER NULL,
    activation_reference TEXT NULL,
    suspension_reason TEXT NULL,
    activated_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS fm_subscriptions_user_status ON fm_subscriptions(user_id, status, expires_at);
CREATE INDEX IF NOT EXISTS fm_subscriptions_package_idx ON fm_subscriptions(package_id);

CREATE TABLE IF NOT EXISTS fm_installations (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id),
    subscription_id TEXT NOT NULL REFERENCES fm_subscriptions(id),
    business_name TEXT NOT NULL,
    hosting_mode TEXT NOT NULL,
    subdomain TEXT NULL,
    domain TEXT NOT NULL,
    status TEXT NOT NULL,
    verification_token TEXT NOT NULL,
    verified_at INTEGER NULL,
    deployment_started_at INTEGER NULL,
    activated_at INTEGER NULL,
    admin_note TEXT NULL,
    released_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS fm_installations_owner ON fm_installations(user_id, status);
CREATE INDEX IF NOT EXISTS fm_installations_entitlement ON fm_installations(subscription_id, released_at);

CREATE TABLE IF NOT EXISTS fm_domain_claims (
    domain TEXT COLLATE BINARY PRIMARY KEY,
    installation_id TEXT NOT NULL UNIQUE REFERENCES fm_installations(id),
    created_at INTEGER NOT NULL
);
