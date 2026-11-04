CREATE TABLE IF NOT EXISTS fm_lifecycle_notices (
    kind TEXT NOT NULL,
    reference_id TEXT NOT NULL,
    owner_id TEXT NOT NULL,
    cycle INTEGER NOT NULL,
    state TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    due_at INTEGER NOT NULL,
    PRIMARY KEY (kind, reference_id, owner_id, cycle)
);
CREATE INDEX IF NOT EXISTS fm_notice_due ON fm_lifecycle_notices (state, due_at);
