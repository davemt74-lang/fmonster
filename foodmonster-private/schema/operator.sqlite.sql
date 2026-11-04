CREATE TABLE IF NOT EXISTS fm_billing_reviews (
    subscription_id TEXT PRIMARY KEY REFERENCES fm_subscriptions(id),
    reason TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);
