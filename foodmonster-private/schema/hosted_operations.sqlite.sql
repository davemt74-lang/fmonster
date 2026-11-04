CREATE TABLE IF NOT EXISTS fm_hosted_control (
    installation_id TEXT PRIMARY KEY REFERENCES fm_hosted_setups(installation_id),
    command TEXT NULL,
    due_at INTEGER NULL,
    failures INTEGER NOT NULL DEFAULT 0,
    reason TEXT NULL
);
CREATE TABLE IF NOT EXISTS fm_hosted_events (
    id INTEGER PRIMARY KEY,
    installation_id TEXT NOT NULL REFERENCES fm_hosted_setups(installation_id),
    event TEXT NOT NULL,
    stage TEXT NOT NULL,
    reason TEXT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS fm_hosted_events_installation ON fm_hosted_events (installation_id, id);
