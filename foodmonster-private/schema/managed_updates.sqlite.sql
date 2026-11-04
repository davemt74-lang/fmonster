CREATE TABLE IF NOT EXISTS fm_managed_updates (
    attempt_id TEXT PRIMARY KEY REFERENCES fm_update_attempts(id),
    installation_id TEXT NOT NULL REFERENCES fm_installations(id),
    from_version TEXT NOT NULL,
    phase TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS fm_managed_updates_queue ON fm_managed_updates (phase, updated_at);
CREATE INDEX IF NOT EXISTS fm_managed_updates_installation ON fm_managed_updates (installation_id, phase);
CREATE TABLE IF NOT EXISTS fm_release_download_events (
    id TEXT PRIMARY KEY,
    release_id TEXT NOT NULL REFERENCES fm_releases(id),
    served_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS fm_release_download_events_time ON fm_release_download_events (release_id, served_at);
