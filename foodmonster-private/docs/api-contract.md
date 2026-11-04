# Food Monster account backend — implementation contract

The production target is PHP 8.2+ and MySQL/MariaDB on the central foodmonster.xyz website. The approved static Sites preview is a visual preview; production account data is never simulated or stored in browser storage. One PHP implementation serves both Food Monster cloud and self-hosted Gelato customers.

## Layout

- `dist/`: public HTML, CSS, JavaScript, and images. No PHP source or credentials are published to the static preview.
- `backend/api/index.php`: JSON API entrypoint, copied to `api/index.php` in the install ZIP.
- `backend/private/`: application classes and configuration, copied to `foodmonster-private/` in the install ZIP; denied by Apache and protected by PHP bootstrap guards.
- `backend/schema/`: MySQL and SQLite test schemas; included under the protected private directory.
- `backend/install.php`: one-time owner setup, shipped as `install.php`.
- `backend/tests/`: integration tests, excluded from the customer install ZIP.

Requests use `api/index.php?action=...`. All JSON success responses are `{ "ok": true, "data": { ... } }`; errors are `{ "ok": false, "error": { "code": "...", "message": "...", "fields": {} } }`. Mutations are POST JSON and require an `X-CSRF-Token` header from bootstrap. User and role are always derived server-side.

## Shared PHP interface

Global namespace classes: `FMDatabase`, `FMSecurity`, `FMAuth`, `FMCommerce`, and `FMError`.

`FMDatabase` exposes public `PDO $pdo`, `string $driver`; `query(string $sql,array $params=[]): array` returns all associative rows; `one(string $sql,array $params=[]): ?array`; `execute(string $sql,array $params=[]): int`; `transaction(callable $fn): mixed`; `id(): string` returns a random hex identifier. SQL uses positional placeholders. Timestamps are Unix seconds.

`FMSecurity` exposes static `requireUser(FMDatabase $db): array`, `requireAdmin(FMDatabase $db): array`, `requireVerified(array $user): void`, `audit(FMDatabase $db,?string $userId,string $event,array $metadata=[]): void`, and `rateLimit(FMDatabase $db,string $scope,int $limit,int $seconds): void`. `FMError(int $status,string $code,string $message,array $fields=[])` is the shared error type.

`FMAuth::dispatch(string $action,array $input,FMDatabase $db,array $config): ?array` and `FMCommerce::dispatch(...)` return response data or null when the action is not owned by that class. The router adds success/error wrappers. Return empty array for successful actions with no data.

`FMAuth::bootstrap(FMDatabase $db,array $config): array` returns `{csrf,user,configured:true,mail_available}`. Public user fields: `id,name,email,email_verified,role`. Role is `customer` or `admin`.

## Account API

- GET `bootstrap` — CSRF and current user.
- POST `auth.signup` — `name,email,password,business_name`; creates customer only; sends verification when configured; response `message`.
- POST `auth.login` — `email,password`; response `user`.
- POST `auth.logout`, `auth.logout_all`.
- POST `auth.forgot` — `email`; generic response `message`.
- POST `auth.reset` — `token,password`; single-use expiring token; revoke all sessions.
- POST `auth.verify` — `token`.
- POST `auth.resend` — authenticated user.
- POST `auth.change_password` — `current_password,password`.
- POST `account.update` — `name,business_name`; email changes excluded from this build.
- GET `account.summary` — user, business, subscriptions, installations, published packages.

## Product API

- GET `packages.list` — published packages.
- POST `subscription.request` — `package_id,billing_interval,hosting_mode`; verified customer; server prices; state `pending_review` until administrator activation. Never claim a payment was taken.
- POST `domains.check` — `subdomain`; verified customer; availability in Food Monster registration records, not a DNS purchase.
- POST `installations.register` — `business_name,hosting_mode,subdomain` (cloud) or `domain` (self_hosted); active eligible subscription; atomic unique reservation; cloud state `reserved`, self-hosted state `verification_pending`.
- POST `installations.verify` — `installation_id`; verified customer, ownership enforced; DNS TXT challenge `foodmonster-verification=<random token>` at `_foodmonster.<domain>` proves control. Return true only after real matching DNS lookup; no user-supplied success flag.
- POST `installations.release` — own unprovisioned record only; release reserved names, keep audit.
- GET `admin.overview` — admin-only packages, subscription requests, installations, customers, and service configuration status.
- POST `admin.package_save` — `id?`, `name,description,monthly_price_cents,quarterly_price_cents,annual_price_cents,currency,hosting_modes,max_installations,features,published`.
- POST `admin.package_archive` — `package_id`.
- POST `admin.subscription_activate` — `subscription_id,expires_at,reference`; record manual activation and audit, no fake Stripe charge.
- POST `admin.subscription_suspend` — `subscription_id,reason`.
- POST `admin.installation_status` — `installation_id,status,note`; administrative lifecycle changes only; `active` requires verified domain. DNS verification is not Gelato deployment. No automatic hosting or installation claim.

## Product records

Package JSON: `id,name,description,monthly_price_cents,quarterly_price_cents,annual_price_cents,currency,hosting_modes` (array of cloud/self_hosted), `max_installations,features` (array), `published,archived`.

Subscription JSON: `id,package_id,package_name,status,billing_interval,hosting_mode,price_cents,currency,expires_at,created_at`.

Installation JSON: `id,business_name,hosting_mode,subdomain,domain,status,verified_at,verification_record,verification_value,created_at`.

Cloud domain suffix defaults to the configured `base_domain` (`foodmonster.xyz` in the example). Reject reserved/invalid names. Names are unique across all active registrations. Releasing a name preserves an audit record.

## Boundaries

Central sessions are host-only: Secure, HttpOnly, SameSite=Lax, Path=/; no shared parent-domain cookie. Passwords are hashed; verification/recovery bearer tokens are hashed and expire; password changes revoke old sessions. Never trust client-provided role, owner, price, paid status, or verification result. Fixed configured URLs generate email links; tokens use URL fragments and are removed from browser history after reading.

No package prices, payment provider, SMTP credentials, cPanel credentials, or Gelato distribution source have been provided. Ship real configurable backend logic and honest pending/empty states. Operator setup is required before real customer use. SMTP/payment/hosting integrations are not simulated. Native PHP mail plus a local-only test capture adapter is acceptable for this build. All tests use local captured email and temporary databases.
