# Food Monster PHP account service

The account API is PHP 8.2 or newer with PDO MySQL. SQLite is a local integration-test adapter and is rejected in production. Customer Gelato applications remain separate from these central accounts.

## Deploy and install

1. Create an empty MySQL/MariaDB database and a database user in your hosting panel. Assign the user to the database with permission to create and use its tables.
2. Upload and extract the installation ZIP into the Food Monster domain's document root. Public files are at the ZIP root: `index.html`, the account pages, `api/index.php`, and `install.php`. Protected classes, configuration, schemas, and operator documentation are under `foodmonster-private/`. Include hidden `.htaccess` files.
3. Open `/install.php` on the HTTPS website.
4. Enter the database host, database name, database username/password, and your first account's name, email, password, and password confirmation.
5. Click **Install & create account**, then sign in. The first account is the platform administrator.

The installer writes the private configuration, generates random application secrets, installs the auth/commerce schemas, creates the administrator, and locks setup. A fresh installation does not require a setup key, a manually edited configuration, or working email delivery. There is no default administrator or password, and customer signup cannot create administrators. Remove `install.php` after successful setup; the installation lock independently prevents reuse.

Hosting requirements are PHP 8.2+, PDO MySQL, HTTPS, and MySQL/MariaDB with InnoDB and utf8mb4. Installation needs CREATE, INDEX, REFERENCES, SELECT, INSERT, UPDATE, and DELETE privileges. PHP must be able to write its protected configuration directory during setup. No Node, Composer, or frontend build is required for the upload ZIP. SQLite remains a local test adapter and is rejected in production.

## Later configuration and hosting details

The generated `foodmonster-private/config.php` contains the database settings, canonical website URL, application secret, session settings, and mail settings. Keep it private. The included Apache rules deny the entire private directory; another webserver must provide equivalent protection. Direct requests to its configuration, schemas, and operator documents must never expose their contents. Back up an existing website/database before deployment.

Configure the host's PHP `mail()` delivery and a valid sender, then enable account mail in private configuration before accepting customer signups or using password recovery. Native mail acceptance does not establish inbox delivery. An administrator can create the first account and sign in before this step. Customer email verification and recovery still require real delivery; no SMTP credential adapter is included.

For an advanced deployment, the configuration may live outside the document root using the PHP environment variable `FOODMONSTER_CONFIG`. Any configured session directory must be private and writable by PHP. Do not share the central account cookie across customer subdomains.

Production HTTPS detection uses the server's HTTPS/443 values and deliberately does not trust arbitrary forwarded headers. If TLS terminates at a trusted reverse proxy, configure the webserver/PHP HTTPS environment correctly. Development and test modes are limited to localhost URLs; production requires MySQL and secure cookies.

## Authentication and request contract

The full shared contract is in `docs/backend-contract.md` in the source repository. The public router accepts `GET api/index.php?action=bootstrap` to establish the session and return its CSRF token. Mutations require POST with `Content-Type: application/json` and `X-CSRF-Token`. Origin and browser fetch-site checks supplement the synchronizer token; CORS is not enabled. Read endpoints have explicit GET-only routing. Responses are JSON success/error envelopes and are never cacheable. Errors do not contain PDO details, paths, credentials or request bodies.

Implemented actions: `auth.signup`, `auth.login`, `auth.logout`, `auth.logout_all`, `auth.forgot`, `auth.reset`, `auth.verify`, `auth.resend`, `auth.change_password`, `account.update`. The router dispatches commerce actions through `FMCommerce`. The bootstrap and successful login/change-password/logout responses return the current CSRF token, which the browser must replace after session rotation. Signup does not automatically authenticate or verify the new account. Profile updates do not change email, role or ownership.

Passwords use Argon2id when supported by the installed PHP runtime and otherwise bcrypt cost 12. New passwords require at least 12 UTF-8 characters. Bcrypt passwords reject more than 72 bytes instead of silently truncating; all passwords reject null bytes and more than 1,024 bytes. Login uses a timing hash for unknown accounts and identical invalid-credential messages. Signup and recovery use generic account-existence responses. Database-backed limits independently constrain IPs and account identifiers. IP identity is the webserver REMOTE_ADDR, not a client-controlled forwarding header. Shared NATs therefore share the IP limit.

Session cookies are host-only, `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, with the `__Host-foodmonster` name over HTTPS. Session IDs and CSRF tokens rotate at login/logout and sensitive account changes. Default expiry is eight hours absolute and one hour idle. Every authenticated request checks the persisted account session version. Password resets and “sign out all sessions” invalidate every prior login; password change invalidates other logins while rotating the current session. Password reset requires a separate new sign-in.

Email verification expires after 24 hours, recovery after one hour. Only SHA-256 token hashes are stored. Tokens are random 256-bit values, scoped by action, consumed atomically in a database transaction, and cannot be replayed. Issuing another token invalidates earlier tokens of that kind. Email links place tokens in URL fragments; the frontend removes them from history immediately and consumes only on an explicit POST. Tokens are not in server request URLs or audit metadata. Captured test email is the only intentional plaintext bearer-token storage and must remain local/private.

## Database and maintenance

Auth owns `users`, `business_profiles`, `auth_tokens`, `rate_limits`, `audit_log`, and `app_meta`. User IDs are 32-character random hex. MySQL user ID foreign keys must match `CHAR(32) CHARACTER SET ascii COLLATE ascii_bin`. Commerce creates its own tables. `FMDatabase::migrate($schemaDirectory)` applies both idempotent baseline schema files; this helper is for fresh installation, not a general versioned ALTER migration system. The installed schema version is recorded in `app_meta`.

Keep database backups and access/error logs under your hosting account's access controls. Audit entries contain action names, internal IDs and limited operational metadata, not passwords or recovery tokens. Configure log retention according to your business requirements. Routine maintenance can delete expired rate buckets and expired/consumed tokens after a suitable operational retention period; deleting them is not required for token expiry enforcement. Never clear `app_meta.installed` as a routine maintenance action.

There is no built-in SMTP credential transport, social login, MFA, payment processor, DNS provisioning, TLS provisioning, automatic hosting creation or Gelato installer in the auth layer. Native mail delivery and the commerce administrator queue are real integrations/states, not simulations. Add and test production service integrations before promising automated delivery.

## Local testing only

Use PHP 8.2+ with PDO SQLite, a local URL, a disposable SQLite database and a private mail capture directory. Test configuration can be loaded by defining `FOODMONSTER_CONFIG_PATH` in a test wrapper or setting `FOODMONSTER_CONFIG`; production uses the same PHP controller and classes.

```php
return [
    'app_env' => 'test',
    'app_url' => 'http://localhost:4173',
    'app_key' => getenv('TEST_APP_KEY'),
    'base_domain' => 'foodmonster.xyz',
    'database' => ['driver' => 'sqlite', 'path' => '/private/test/account.sqlite'],
    'session' => ['save_path' => '/private/test/sessions'],
    'mail' => ['enabled' => true, 'transport' => 'capture', 'capture_path' => '/private/test/mail', 'from' => 'accounts@foodmonster.xyz', 'from_name' => 'Food Monster'],
];
```

Generate ephemeral application keys at runtime; do not reuse production secrets. `capture` is rejected unless the environment is `test`/`development` and the configured host is localhost, 127.0.0.1, ::1, or terminal.local. It creates permission-restricted random-name JSON files containing `to`, `subject`, `text`, `html` and `created_at`; it never invokes native mail. Local HTTP uses `foodmonster_development` cookies, so the secure production cookie policy can be tested separately over HTTPS.

The integration harness under `backend/tests/` is excluded from customer packages. Tests should exercise the actual API controller, including installation, CSRF/method checks, signup-role isolation, login/logout, email verification, token replay/expiry, reset and session revocation, profile isolation, and the commerce authorization and reservation flows. Never run test capture or test database helpers against production.
