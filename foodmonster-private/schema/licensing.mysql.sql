CREATE TABLE IF NOT EXISTS fm_licenses (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(16) NOT NULL,
    expires_at BIGINT NULL,
    updates_enabled TINYINT NOT NULL DEFAULT 1,
    channel VARCHAR(16) NOT NULL DEFAULT 'stable',
    auto_update VARCHAR(16) NOT NULL DEFAULT 'off',
    instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    installed_version VARCHAR(64) NULL,
    activated_at BIGINT NULL,
    last_check_in BIGINT NULL,
    latest_attempt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    health VARCHAR(16) NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    FOREIGN KEY (installation_id) REFERENCES fm_installations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_releases (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    channel VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    notes TEXT NOT NULL,
    package_name VARCHAR(180) NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    package_bytes BIGINT NULL,
    minimum_version VARCHAR(64) NULL,
    migration_required TINYINT NOT NULL,
    automatic_compatible TINYINT NOT NULL,
    rollback_notes TEXT NOT NULL,
    created_at BIGINT NOT NULL,
    published_at BIGINT NULL,
    UNIQUE (version, channel),
    INDEX fm_release_status_channel (status, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_update_attempts (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    license_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    release_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    mode VARCHAR(16) NOT NULL,
    state VARCHAR(16) NOT NULL,
    error_summary VARCHAR(500) NULL,
    started_at BIGINT NOT NULL,
    finished_at BIGINT NULL,
    FOREIGN KEY (license_id) REFERENCES fm_licenses(id),
    FOREIGN KEY (release_id) REFERENCES fm_releases(id),
    INDEX fm_update_license_started (license_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
