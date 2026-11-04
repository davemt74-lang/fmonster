CREATE TABLE IF NOT EXISTS fm_hosted_setups (
    installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    hosting_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    release_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'queued',
    stage VARCHAR(20) NOT NULL DEFAULT 'queued',
    owner_profile TEXT NOT NULL,
    password_hash VARCHAR(255) NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    CONSTRAINT fm_hosted_setup_installation FOREIGN KEY (installation_id) REFERENCES fm_installations(id),
    CONSTRAINT fm_hosted_setup_release FOREIGN KEY (release_id) REFERENCES fm_releases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_hosting_settings (
    id INT PRIMARY KEY,
    hostname VARCHAR(253) NOT NULL,
    username VARCHAR(32) NOT NULL,
    database_prefix VARCHAR(32) NOT NULL,
    encrypted_token TEXT NOT NULL,
    tested_at BIGINT NULL,
    updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
