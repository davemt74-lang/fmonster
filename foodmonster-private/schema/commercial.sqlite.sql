CREATE TABLE IF NOT EXISTS fm_quarterly_prices (
    package_id TEXT PRIMARY KEY REFERENCES fm_packages(id),
    price_cents INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS fm_subdomain_terms (
    installation_id TEXT PRIMARY KEY REFERENCES fm_installations(id),
    expires_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS fm_subdomain_transfers (
    id TEXT PRIMARY KEY,
    installation_id TEXT NOT NULL REFERENCES fm_installations(id),
    sender_id TEXT NOT NULL REFERENCES users(id),
    recipient_id TEXT NOT NULL REFERENCES users(id),
    state TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS fm_transfer_site ON fm_subdomain_transfers(installation_id, state, expires_at);
CREATE INDEX IF NOT EXISTS fm_transfer_recipient ON fm_subdomain_transfers(recipient_id, state, expires_at);

CREATE TABLE IF NOT EXISTS fm_stripe_billing (
    subscription_id TEXT PRIMARY KEY REFERENCES fm_subscriptions(id),
    checkout_email TEXT NOT NULL,
    checkout_id TEXT UNIQUE,
    stripe_id TEXT UNIQUE,
    stripe_customer TEXT,
    live_mode INTEGER NOT NULL,
    state TEXT NOT NULL
);
