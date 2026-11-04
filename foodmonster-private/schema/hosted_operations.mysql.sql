CREATE TABLE IF NOT EXISTS fm_hosted_control (
    installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    command VARCHAR(16) NULL,
    due_at BIGINT NULL,
    failures INT NOT NULL DEFAULT 0,
    reason VARCHAR(40) NULL,
    CONSTRAINT fm_hosted_control_setup FOREIGN KEY (installation_id) REFERENCES fm_hosted_setups(installation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_hosted_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event VARCHAR(40) NOT NULL,
    stage VARCHAR(20) NOT NULL,
    reason VARCHAR(40) NULL,
    created_at BIGINT NOT NULL,
    KEY fm_hosted_events_installation (installation_id, id),
    CONSTRAINT fm_hosted_events_setup FOREIGN KEY (installation_id) REFERENCES fm_hosted_setups(installation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
