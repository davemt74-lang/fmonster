CREATE TABLE IF NOT EXISTS fm_packages (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT NOT NULL,
    monthly_price_cents BIGINT NULL,
    annual_price_cents BIGINT NULL,
    currency CHAR(3) NOT NULL,
    hosting_modes TEXT NOT NULL,
    max_installations INT NOT NULL,
    features TEXT NOT NULL,
    published TINYINT(1) NOT NULL DEFAULT 0,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    INDEX fm_packages_public (published, archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_subscriptions (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    package_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    package_name VARCHAR(120) NOT NULL,
    status VARCHAR(32) NOT NULL,
    billing_interval VARCHAR(16) NOT NULL,
    hosting_mode VARCHAR(16) NOT NULL,
    price_cents BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    max_installations INT NOT NULL,
    expires_at BIGINT NULL,
    activation_reference VARCHAR(200) NULL,
    suspension_reason TEXT NULL,
    activated_at BIGINT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    CONSTRAINT fm_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fm_subscriptions_package FOREIGN KEY (package_id) REFERENCES fm_packages(id),
    INDEX fm_subscriptions_user_status (user_id, status, expires_at),
    INDEX fm_subscriptions_package_idx (package_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_installations (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    business_name VARCHAR(160) NOT NULL,
    hosting_mode VARCHAR(16) NOT NULL,
    subdomain VARCHAR(63) NULL,
    domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) NOT NULL,
    verification_token VARCHAR(64) NOT NULL,
    verified_at BIGINT NULL,
    deployment_started_at BIGINT NULL,
    activated_at BIGINT NULL,
    admin_note TEXT NULL,
    released_at BIGINT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    CONSTRAINT fm_installations_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fm_installations_subscription FOREIGN KEY (subscription_id) REFERENCES fm_subscriptions(id),
    INDEX fm_installations_owner (user_id, status),
    INDEX fm_installations_entitlement (subscription_id, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_domain_claims (
    domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    created_at BIGINT NOT NULL,
    CONSTRAINT fm_domain_claims_installation FOREIGN KEY (installation_id) REFERENCES fm_installations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
