CREATE TABLE IF NOT EXISTS fm_signed_releases (
    release_id TEXT PRIMARY KEY REFERENCES fm_releases(id),
    signature TEXT NOT NULL,
    public_key_sha256 TEXT NOT NULL,
    source_commit TEXT NOT NULL
);
