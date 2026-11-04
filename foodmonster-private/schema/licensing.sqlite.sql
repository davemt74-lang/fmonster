CREATE TABLE IF NOT EXISTS fm_licenses (
    id TEXT PRIMARY KEY,
    installation_id TEXT NOT NULL UNIQUE,
    credential_hash TEXT NOT NULL,
    state TEXT NOT NULL,
    expires_at INTEGER NULL,
    updates_enabled INTEGER NOT NULL DEFAULT 1,
    channel TEXT NOT NULL DEFAULT 'stable',
    auto_update TEXT NOT NULL DEFAULT 'off',
    instance_id TEXT NULL,
    installed_version TEXT NULL,
    activated_at INTEGER NULL,
    last_check_in INTEGER NULL,
    latest_attempt_id TEXT NULL,
    health TEXT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY (installation_id) REFERENCES fm_installations(id)
);

CREATE TABLE IF NOT EXISTS fm_releases (
    id TEXT PRIMARY KEY,
    version TEXT NOT NULL,
    channel TEXT NOT NULL,
    status TEXT NOT NULL,
    notes TEXT NOT NULL,
    package_name TEXT NOT NULL,
    checksum TEXT NULL,
    package_bytes INTEGER NULL,
    minimum_version TEXT NULL,
    migration_required INTEGER NOT NULL,
    automatic_compatible INTEGER NOT NULL,
    rollback_notes TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    published_at INTEGER NULL,
    UNIQUE (version, channel)
);

CREATE TABLE IF NOT EXISTS fm_update_attempts (
    id TEXT PRIMARY KEY,
    license_id TEXT NOT NULL,
    release_id TEXT NOT NULL,
    instance_id TEXT NULL,
    mode TEXT NOT NULL,
    state TEXT NOT NULL,
    error_summary TEXT NULL,
    started_at INTEGER NOT NULL,
    finished_at INTEGER NULL,
    FOREIGN KEY (license_id) REFERENCES fm_licenses(id),
    FOREIGN KEY (release_id) REFERENCES fm_releases(id)
);

CREATE INDEX IF NOT EXISTS fm_release_status_channel ON fm_releases (status, channel);
CREATE INDEX IF NOT EXISTS fm_update_license_started ON fm_update_attempts (license_id, started_at);
