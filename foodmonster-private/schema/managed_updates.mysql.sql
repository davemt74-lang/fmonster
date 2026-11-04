CREATE TABLE IF NOT EXISTS fm_managed_updates (
    attempt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    from_version VARCHAR(64) NOT NULL,
    phase VARCHAR(16) NOT NULL,
    updated_at BIGINT NOT NULL,
    CONSTRAINT fm_managed_updates_attempt FOREIGN KEY (attempt_id) REFERENCES fm_update_attempts(id),
    CONSTRAINT fm_managed_updates_site FOREIGN KEY (installation_id) REFERENCES fm_installations(id),
    KEY fm_managed_updates_queue (phase, updated_at),
    KEY fm_managed_updates_installation (installation_id, phase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_release_download_events (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    release_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    served_at BIGINT NOT NULL,
    CONSTRAINT fm_release_download_events_release FOREIGN KEY (release_id) REFERENCES fm_releases(id),
    KEY fm_release_download_events_time (release_id, served_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
