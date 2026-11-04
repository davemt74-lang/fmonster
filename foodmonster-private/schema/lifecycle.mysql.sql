CREATE TABLE IF NOT EXISTS fm_lifecycle_notices (
    kind VARCHAR(24) NOT NULL,
    reference_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cycle BIGINT NOT NULL,
    state VARCHAR(12) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    due_at BIGINT NOT NULL,
    PRIMARY KEY (kind, reference_id, owner_id, cycle),
    INDEX fm_notice_due (state, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
