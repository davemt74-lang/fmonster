CREATE TABLE IF NOT EXISTS users (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, email_verified_at BIGINT NULL,
 role VARCHAR(16) NOT NULL DEFAULT 'customer', session_version INT NOT NULL DEFAULT 1,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS business_profiles (
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 business_name VARCHAR(160) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 CONSTRAINT fk_business_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS auth_tokens (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 type VARCHAR(16) NOT NULL, expires_at BIGINT NOT NULL, used_at BIGINT NULL, created_at BIGINT NOT NULL,
 INDEX auth_tokens_user_type(user_id,type,expires_at),
 CONSTRAINT fk_token_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS rate_limits (
 bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 hits INT NOT NULL, reset_at BIGINT NOT NULL, INDEX rate_limits_expiry(reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS audit_log (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 event VARCHAR(80) NOT NULL, metadata TEXT NOT NULL, created_at BIGINT NOT NULL,
 INDEX audit_log_user_time(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS app_meta (
 key_name VARCHAR(64) PRIMARY KEY,value_text TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
