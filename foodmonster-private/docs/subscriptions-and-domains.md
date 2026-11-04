# Packages, subscriptions, and installation registry

`private/Commerce.php` implements the central Food Monster subscription and domain APIs. It requires the core authentication database and classes. Apply `schema/auth.mysql.sql` followed by `schema/commerce.mysql.sql` for MySQL/MariaDB. The corresponding SQLite schemas support the local integration suite. Production targets PHP 8.2+ with PDO MySQL and DNS TXT resolution.

## Configuring the catalog

The administrator creates packages in the dashboard. Packages support a name, description, feature list, currency, monthly, quarterly, and annual prices, supported hosting options, and an installation limit. A `null` price disables an interval. Set a price of `0` to offer it free. Prices use whole minor-currency units with two decimal places. For example, a display price of 12.34 is stored as 1234. Supported currencies are USD, EUR, GBP, CAD, AUD, NZD, CHF, SEK, NOK, DKK, SGD, HKD, and ZAR. Drafts stay private and publishing requires at least one configured interval. Archiving removes a package from new requests and retains its subscription history.

A customer's request snapshots the package name, price, currency, hosting option, and installation allowance on the server. Manual requests remain `pending_review` until an administrator records a reference and future expiry. When Stripe is enabled, paid Cloud packages activate and renew from verified payments. An inactive entitlement blocks new registrations and lifecycle activation. Coordinate changes to running customer hosting separately.

## Subdomains and self-hosted installations

Set `base_domain` in the private configuration to the central cloud suffix, for example `foodmonster.xyz`. The built-in denylist protects platform/service names; `reserved_subdomains` can add names. Cloud labels are 3–40 characters, start with a letter, and allow letters, digits, and single hyphens. Registration checks an active matching subscription and its installation limit inside a database transaction. MySQL locks the customer's row; SQLite uses the core `BEGIN IMMEDIATE` transaction. A unique canonical domain claim prevents simultaneous registrations from taking the same name.

The availability endpoint checks the platform registry. It is advisory and does not purchase domains or inspect an external DNS account. Cloud registrations begin `reserved`. Self-hosted customer domains begin `verification_pending`. A reservation does not create a DNS entry, virtual host, TLS certificate, or Gelato installation. Existing external DNS configuration must be reconciled by the operator before enabling customer registration on a previously used suffix.

For domain verification, publish a TXT record at the exact returned `verification_record` with the exact returned `verification_value`. It has this form:

```
_foodmonster.order.restaurant.com TXT foodmonster-verification=<random challenge>
```

The endpoint uses PHP `dns_get_record` to query TXT records and compare the full value. Customer-supplied `verified`, `success`, owner, price, or status fields cannot grant ownership or access. Customer operations are ownership-filtered; administrators may verify customer domains using the same actual TXT lookup. Verification marks domain control only, never successful software deployment. The sole DNS test adapter is a callable `dns_txt_resolver` in an operator-controlled configuration whose `app_env` is exactly `test`; it cannot be selected through an HTTP request. Production refuses reserved test/internal names and performs no HTTP fetch to user-controlled hosts.

Unused reservations can be released by their owner. The installation row and audit remain, while its unique claim is freed. Once deployment has started or an installation has ever been active, release is denied to prevent freeing a domain that may still serve a customer's application. Unregistering a deployed environment requires an operator-coordinated teardown; there is no unsafe automatic removal route.

## Manual deployment lifecycle

Administrator status choices are `verification_pending`, `provisioning`, `active`, `suspended`, and `failed`, subject to permitted transitions. `reserved` and `verified` are derived by registration and DNS verification; `released` is produced by safe release. Every administrative lifecycle change requires an explanatory note. Returning a previously verified, failed installation to `verification_pending` clears its old proof and generates a fresh DNS challenge. `provisioning` and `active` require a current subscription; `active` also requires a successful DNS verification. The administrator must actually configure the hosting, TLS, and business-specific Gelato installation before recording it active. The API records that operational decision and never claims to perform it.

See [Cloud subscriptions and subdomain registration](BILLING.md) for payment, annual renewal, and transfer setup. See [Hosted Gelato setup](HOSTED-SETUP.md) for cPanel provisioning and managed updates.

## API response details

All actions use the core router's `{ok,data}` / `{ok:false,error}` wrapper and CSRF/session enforcement.

- `packages.list`: `{packages}`. Only published, nonarchived entries.
- `account.summary`: `{user,business,packages,subscriptions,installations,base_domain}`; business contains `business_name`.
- `domains.check`: `{available,subdomain,domain,message}`. Registration is the atomic reservation step.
- `subscription.request` returns `{subscription,message}` for manual requests. Billing intervals are `monthly`, `quarterly`, and `annual`. Hosting modes are `cloud` and `self_hosted`.
- Installation mutations: `{installation,message}`. Verification also returns `verified` as a Boolean derived only from DNS.
- `admin.overview`: `{packages,subscriptions,installations,customers,configuration}`. Customer email/name and manual reference/reason fields are included only in the administrator view.
- Package mutations: `{package,message}`; subscription mutations: `{subscription,message}`.

Subscription responses report `expired` once the stored expiry passes, even if the persisted prior lifecycle value is `active`. Eligibility always checks time on the server. Installation responses include `can_release`, deployment/activation timestamps, and DNS challenge fields. Identity, roles, prices, status, expiry eligibility, package snapshots, limits, and audit actors are derived server-side.
