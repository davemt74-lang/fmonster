CREATE TABLE IF NOT EXISTS fm_billing_reviews (
    subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    reason VARCHAR(64) NOT NULL,
    updated_at BIGINT NOT NULL,
    FOREIGN KEY (subscription_id) REFERENCES fm_subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
