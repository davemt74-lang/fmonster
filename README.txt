Food Monster Platform 1.7.0 — Existing-site upgrade

1. Back up the existing Food Monster files and MySQL/MariaDB database.
2. Pause the hosted worker cron, then upload this ZIP to the Food Monster document root and extract it there.
   Keep the existing private config.php, installation lock and data.
3. Sign in to Food Monster as an existing platform administrator.
4. Open https://foodmonster.xyz/upgrade.php and select Run database upgrade.
5. Run Admin → cPanel → Run preflight, then follow foodmonster-private/docs/HOSTED-SETUP.md to test a disposable subdomain.
6. Open Admin → Update analytics to see current versions and release outcomes.
   To enable owner-approved hosted updates, follow the opt-in and signing instructions in HOSTED-SETUP.md.
7. Set monthly, quarterly, or annual prices in Admin → Packages. See foodmonster-private/docs/BILLING.md for Stripe setup, renewals, and transfers.

Open Admin → Operations to find pending setup, hosting issues, billing follow-up,
and expiring Cloud registrations. Customers can resume from My account → Cloud setup.
Import signed Gelato bundles in Admin → Releases. Review the stable draft before
publishing. Expired unused registrations can be reviewed for retirement after
the 30-day grace period in Admin → Operations → Expiring domains.

PHP 8.2+, MySQL/MariaDB, HTTPS and the included Apache access rules are required.
Enable PHP ZipArchive and configure a private package directory to publish ZIPs.
Read foodmonster-private/docs/LICENSING-UPGRADE.md for activation and release publishing.

Lifecycle email is off by default. After the database upgrade, configure the
mail transport, set mail.lifecycle_enabled to true in the private config, and
schedule foodmonster-private/lifecycle-worker.php with the same PHP CLI used by
hosting. Run it every five minutes. Use --status for counts by type and state.
The worker checks the current verified recipient before sending. It retries
known transport failures twice. A process interrupted during delivery leaves
a sending record for review against the server mail log. Check the mail log
before manually retrying an interrupted delivery. A sent status means the
configured transport accepted the message.
