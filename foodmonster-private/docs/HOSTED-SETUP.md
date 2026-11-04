# Hosted Gelato setup

Pause the hosted worker cron and let any running job finish. Upload and extract Food Monster 1.6.1 over the existing portal, sign in as administrator, and run `/upgrade.php`. Keep the existing configuration, installation lock, and database. Before resuming the worker, configure the trusted hosted release below.

## Connect cPanel

In **Admin → cPanel**, enter the HostGator server hostname, cPanel username, MySQL name prefix, and API token. Enter the prefix without its trailing underscore. If prefixing is disabled, leave it blank. Select **Save connection**, then **Test connection**. The test reads the account's domains over HTTPS on port 2083 and verifies that the account owns the configured Food Monster parent domain. The token is encrypted using the Food Monster application key and is write-only in the UI. If you change the server or username, enter a token for that account.

The browser API requires a platform administrator, CSRF validation, and production HTTPS. The worker requires PHP CLI with cURL, PDO MySQL, Sodium, ZipArchive, mbstring, and `proc_open`. Keep the existing Food Monster application key available to decrypt the token and resume existing jobs.

## Prepare the hosting directories and release

Create a dedicated tenant directory and a private worker directory on the same filesystem under the cPanel account home. The tenant directory needs to be a separate document root hierarchy from Food Monster. The private worker directory needs mode 0700 and must be outside every web document root and alias. Add the following settings to the existing private Food Monster configuration, adapting the paths and database host to your account.

```php
'hosting' => [
    'account_home' => '/home/ACCOUNT',
    'tenant_root' => '/home/ACCOUNT/gelato-sites',
    'private_root' => '/home/ACCOUNT/gelato-worker',
    'database_host' => 'localhost',
    'database_port' => 3306,
    'release_version' => '1.1.1',
    'release_public_key' => 'BASE64_ED25519_PUBLIC_KEY',
    'release_signatures' => ['1.1.1' => 'BASE64_RELEASE_SIGNATURE'],
],
```

The cPanel account must allow creating subdomains with that dedicated document-root layout, and the worker must have filesystem access as the hosting account. Confirm the layout on a disposable subdomain before onboarding customers. If the host restricts subdomains to a shared parent document root, configure separate site document roots or use a dedicated hosting account that permits the required layout. Folder and database separation on a single cPanel account shares the same Unix account boundary.

In **Admin → Releases**, upload and publish the stable **gelato-hosted.zip** produced by the Gelato **Full Deploy Package** workflow. Use the ZIP inside the GitHub artifact. Configure the public key and detached signature in the private file above, and explicitly select its version for new installs. The signature covers the exact ZIP SHA-256 and release version. Obtain the key and signature through your trusted release channel. The portal verifies them before any hosted code runs. Keep the signing key separate from the web server and Git source. See the Gelato **License & Updates** guide for protected CI or offline signing.

The included `HOSTED-RELEASE-1.1.1.txt` lists the exact ZIP checksum and configuration values for the matching deployment package.

A newer public update leaves the selected provisioning version in place. For jobs already in progress, keep the pinned release published and its signature configured until they finish. Releases staged before signature verification require cancellation and cleanup before requeueing. Existing completed sites keep their licenses and operation.

## Run hosting preflight

Use **Admin → cPanel → Run preflight** after saving and testing the connection. It checks the local directory layout, the parent document root and cPanel policy, available quota data, AutoSSL status for the parent, the current PHP runtime, and the published signed release. Checks that cPanel cannot report appear as **Review**. The preflight reads cPanel settings without creating hosting resources.

Run the same checks from the hosting account shell with the exact PHP executable configured for cron.

```bash
php /absolute/path/to/foodmonster-private/hosted-worker.php --preflight
```

A new tenant's DNS, certificate, and Gelato health are verified during the disposable installation.

## Run the first setup

1. Have a customer with an active Cloud subscription open **My restaurant → Set up your Gelato site**. The form collects the owner details, first location, timezone, hostname, and a separate Gelato password. The owner email comes from the signed-in Food Monster account. The password is hashed before the reservation and setup job are committed.
2. From the hosting account shell, run one job using the installation ID shown in **Admin → Hosting jobs**.

   ```bash
   php /absolute/path/to/foodmonster-private/hosted-worker.php INSTALLATION_ID
   ```

3. The worker creates a dedicated subdomain, database and database user. It preserves an empty `cgi-bin` and a standard `.well-known` directory when publishing. Managed Gelato sites close public first-user setup. A pre-issued, unbound active license keeps its record and policy and receives a new installation credential at activation.
4. The command reports `awaiting_https` until the HTTPS Gelato endpoint can access the database, the protected owner, and the scoped license connection. The portal then activates the license and displays **Open your Gelato site**. Food Monster clears the staged owner profile and password hash when the job finishes.
5. After verifying the disposable deployment, use the same PHP CLI command with `--next` in cPanel Cron. A run processes one due job or operator request and skips locked jobs. Transient cPanel failures back off for up to five retries, and HTTPS or application health is checked up to 24 times with delays capped at one hour. The job returns to the operator after its retry budget is exhausted.

Manual and self-hosted installations retain their existing registration and first-owner workflows.

## Enable owner-approved hosted updates

1. Set `'managed_updates_enabled' => true` inside the existing private `hosting` settings. The default is off. Resume the `--next` worker cron.
2. Publish a newer stable hosted ZIP with the Gelato HTTPS health endpoint. Select **Compatible with automatic file updates**, leave **Requires a database upgrade** off, and add the trusted detached signature for this exact version and ZIP. Keep `database/` and `migrations/` identical to the supported installed versions. A release that changes either directory requires manual deployment.
3. Sign into the owning Food Monster account, open **My restaurant**, select **Apply hosted update**, and approve the displayed domain and version. The next available cron run can process it. The saved automatic-update preference never queues an update.

The portal currently trusts one Ed25519 public key. Use an operator-controlled offline or protected CI key for future releases. If you change the trusted key, re-sign the exact archive selected for new installs with that key, or select a newer archive signed by it. Keep the private key outside the web account. Already deployed sites can be updated without re-signing their local files.

The worker verifies the current domain and version, then stages the signed ZIP beside a private backup directory on the same filesystem. It carries forward configuration, managed runtime paths, and ACME files. The database and external private Gelato storage stay in place. Allow enough cPanel quota for the staged release and a private copy of the previous code per successful update. Schedule updates for a low-traffic window because swapping directories can briefly interrupt requests. The web PHP runtime must revalidate updated code. If PHP OPcache timestamp validation is disabled, use a manual deployment with the hosting provider's PHP cache reset procedure.

The worker checks the deployed version over HTTPS and restores the previous code if the check fails. An interrupted swap is eligible for automatic recovery on the next due worker run. The update attempt ID and recovery status are shown in the portal. If recovery needs operator attention, fix the hosting or application issue and run the following command with the original hosting settings. Keep the private update directory while recovery is pending.

```bash
php /absolute/path/to/foodmonster-private/hosted-worker.php --recover-update ATTEMPT_ID
```

Private code backups from successful deployments stay under the installation's private worker directory. Review retention as part of normal hosting maintenance. Keep separate database backups. For migration releases and self-hosted sites, use the manual download and deployment flow.

## Recover or cancel a failed setup

Open **Admin → Hosting jobs** to inspect the timeline and failure reason. **Retry setup** queues an immediate retry and resets its retry budget. **Cancel setup** queues a cancellation for the worker. When resources still exist, the dashboard keeps the reservation and offers **Verify cleanup and release**. Both dashboard actions run through the next worker invocation.

Run the worker again with the installation ID to resume a verified stage or an interrupted, journaled database import. For a setup you want to abandon, run `hosted-worker.php --cancel INSTALLATION_ID` with the same PHP CLI and hosting configuration. It clears staged owner details and releases an untouched reservation. If hosting resources may exist, cancellation retains the reservation while you review the resources in cPanel. After backing up any data you want to keep and removing that job's domain, document root, database and database user, run `hosted-worker.php --release-cancelled INSTALLATION_ID`. The worker checks those resources are absent before releasing the name. The commands leave completed sites active.
