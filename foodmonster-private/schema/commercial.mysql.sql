CREATE TABLE IF NOT EXISTS fm_quarterly_prices (
    package_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    price_cents BIGINT NOT NULL,
    FOREIGN KEY (package_id) REFERENCES fm_packages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_subdomain_terms (
    installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    expires_at BIGINT NOT NULL,
    FOREIGN KEY (installation_id) REFERENCES fm_installations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_subdomain_transfers (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sender_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(16) NOT NULL,
    expires_at BIGINT NOT NULL,
    FOREIGN KEY (installation_id) REFERENCES fm_installations(id),
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id),
    INDEX fm_transfer_site (installation_id, state, expires_at),
    INDEX fm_transfer_recipient (recipient_id, state, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_stripe_billing (
    subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    checkout_email VARCHAR(254) NOT NULL,
    checkout_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    stripe_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    stripe_customer VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    live_mode TINYINT(1) NOT NULL,
    state VARCHAR(24) NOT NULL,
    FOREIGN KEY (subscription_id) REFERENCES fm_subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
