CREATE TABLE IF NOT EXISTS fm_hosted_setups (
    installation_id TEXT PRIMARY KEY REFERENCES fm_installations(id),
    hosting_fingerprint TEXT NULL,
    release_id TEXT NULL REFERENCES fm_releases(id),
    state TEXT NOT NULL DEFAULT 'queued',
    stage TEXT NOT NULL DEFAULT 'queued',
    owner_profile TEXT NOT NULL,
    password_hash TEXT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS fm_hosting_settings (
    id INTEGER PRIMARY KEY,
    hostname TEXT NOT NULL,
    username TEXT NOT NULL,
    database_prefix TEXT NOT NULL,
    encrypted_token TEXT NOT NULL,
    tested_at INTEGER NULL,
    updated_at INTEGER NOT NULL
);
