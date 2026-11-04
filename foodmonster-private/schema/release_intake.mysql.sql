CREATE TABLE IF NOT EXISTS fm_signed_releases (
    release_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    signature VARCHAR(88) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_key_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_commit CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    FOREIGN KEY (release_id) REFERENCES fm_releases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
