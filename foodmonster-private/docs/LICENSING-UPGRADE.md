# Food Monster 1.2.1

## Upgrade the installed site

Back up the website files and database. Upload the ZIP to the installed Food Monster document root and extract it there. Keep the existing `foodmonster-private/config.php`, install lock, and data. Sign in with the existing platform administrator, open `/upgrade.php`, and select **Run database upgrade** if the licensing schema is still pending. Version 1.2.1 uses the 1.2.0 licensing schema.

The upgrader adds `fm_licenses`, `fm_releases`, and `fm_update_attempts` with their indexes, records a schema marker in the existing metadata table, and records the upgrade in the audit log. Existing subscriptions, accounts, and installations retain their IDs and data. Refreshing the page reports completion. A repeat POST returns the completed state. If a MySQL DDL statement fails, check CREATE permissions and retry.

## Register and activate Gelato

1. Approve the customer's subscription in the Food Monster portal and record its expiry.
2. Use **Admin → Subdomains → Add subdomain** to reserve a cloud hostname for a customer with an active Cloud subscription and a free installation slot. Customers can also register their own hosted or self-hosted installation. For a cloud reservation, complete hosting and TLS setup on the hosting account. Use the displayed DNS TXT challenge to verify domain control. In **Admin → Installations**, record the installation as active when ready.
3. Open **Admin → Licenses → Create license** and select that installation. Keep the displayed installation ID and key private. The key is shown once and stored centrally only as a hash. The customer can generate a new key from **My restaurant** after the installation is verified and active. Rotation disconnects the old credential.
4. Deploy the matching Gelato build. In Gelato, open **Admin → License & Updates** as the protected owner, enter the ID and key, and confirm with the owner password. The hostname in Gelato's configured HTTPS site URL must match the registration.
5. Refresh the Food Monster dashboard after activation to see the reported build and last check-in.

A license is linked to one installation. The subscription controls allowed installation count and caps the access period. License expiry, suspension, and revocation pause new releases while the restaurant can keep operating.

Email can remain disabled. When it is disabled, signup, subscriptions and registration work without verification. The email remains unverified. When mail is enabled, verification is required by default. Set `mail.require_verification` to `false` in the existing configuration to enable delivery before enforcing verification.

## Publish a release

1. Create a dedicated directory outside every public document root and web alias. Add `'releases' => ['private_directory' => '/absolute/private/gelato-releases'],` to the existing Food Monster configuration. Enable the PHP ZipArchive extension. Keep published ZIP files immutable and use a unique filename for each package.
2. Prepare a direct-extract Gelato update ZIP. The archive must contain `index.php`, `upgrade.php`, `includes/bootstrap.php` and a matching `GELATO_VERSION.txt` at the application root. Use the deployment build's application files and omit `config.php`, `.env`, `storage/` (including its placeholder), `uploads/`, and private storage from the distribution copy. Existing Gelato webserver rules protect the deployed private paths.
3. In **Admin → Releases → Upload release ZIP**, select the archive and enter the version, channel, notes, optional minimum version, and compatibility flags. Food Monster validates the archive and records its SHA-256 and size on a private draft. Review the draft and select **Publish** when ready. If your host's PHP upload limit is smaller than the ZIP, place it in the configured private release directory using your hosting tools and select **Use staged ZIP**. Published bytes are rechecked before download. Withdrawing a release removes it from new downloads and preserves its history.
4. In **My restaurant**, review and approve the exact available release. Back up Gelato, verify the ZIP's SHA-256, and extract it over the application root. Apply pending migrations through Gelato's existing `upgrade.php`. After a health check, report the outcome in Gelato's **License & Updates** page. If the deployment fails, report the failure before attempting another release.
5. Automatic update preference is stored per installation and defaults to Off. A ready managed Cloud site can use owner-approved deployment when the operator enables it and publishes an eligible signed, file-only release. See [Hosted Gelato setup](HOSTED-SETUP.md) for the signature, backup, and recovery requirements.

## Update analytics

Open **Admin → Update analytics** for adoption by channel and version across active, connected sites and results for each published or withdrawn release. Release outcomes count each requesting installation once. An open attempt counts as pending. Otherwise, an installation with a successful attempt counts as succeeded. All other requesting installations count as failed. Verified rollback counts track individual managed recovery attempts.

ZIP delivery counts cover completed server-side manual responses from the time this upgrade is installed. A response count can include repeated downloads and does not verify receipt or installation. Open **Admin → Updates** for individual attempts and **Admin → Releases** for ZIPs and release notes.

## Server connection

Gelato sends JSON POST requests to `https://foodmonster.xyz/api/installation.php` with a bearer credential and the installation ID, instance ID, configured hostname, and local version. It supports `activate`, `status`, `heartbeat`, and `report`. It uses TLS and authenticates independently of portal cookies and CSRF. Portal mutations and manual approval retain session and CSRF protection. Preserve the included `api/.htaccess` so Apache CGI/FastCGI can pass the credential header to PHP.
