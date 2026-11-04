CREATE TABLE IF NOT EXISTS users (
 id TEXT PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
 password_hash TEXT NOT NULL, email_verified_at INTEGER NULL,
 role TEXT NOT NULL DEFAULT 'customer' CHECK(role IN ('customer','admin')),
 session_version INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS business_profiles (
 user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 business_name TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS auth_tokens (
 token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 type TEXT NOT NULL CHECK(type IN ('verify','reset')), expires_at INTEGER NOT NULL,
 used_at INTEGER NULL, created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS auth_tokens_user_type ON auth_tokens(user_id,type,expires_at);
CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY,hits INTEGER NOT NULL,reset_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS rate_limits_expiry ON rate_limits(reset_at);
CREATE TABLE IF NOT EXISTS audit_log (id TEXT PRIMARY KEY,user_id TEXT NULL,event TEXT NOT NULL,metadata TEXT NOT NULL,created_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS audit_log_user_time ON audit_log(user_id,created_at);
CREATE TABLE IF NOT EXISTS app_meta (key_name TEXT PRIMARY KEY,value_text TEXT NOT NULL);
