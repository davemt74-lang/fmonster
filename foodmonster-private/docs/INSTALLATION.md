# Install Food Monster

## Five steps

1. **Create the database.** In your hosting panel, create an empty MySQL/MariaDB database and a database user. Assign the user to that database with permission to create and use its tables. Keep the database host, name, username, and password handy.
2. **Upload and extract.** Put the ZIP in the document root for your Food Monster domain and extract it there. `index.html`, `install.php`, `api/`, and `foodmonster-private/` should be directly in that root. Keep the included hidden `.htaccess` files.
3. **Open the installer.** Visit `https://foodmonster.xyz/install.php`, using your own domain if different.
4. **Fill in the form.** Enter the database host, database name, database username/password, then your name, email, password, and password confirmation. This creates your first administrator account.
5. **Install and log in.** Click **Install & create account**, then use the login link.

The installer writes its configuration, generates random application secrets, creates the tables and administrator, and locks setup. There is no manual config-file or setup-key step. Email delivery is not required to create the first account.

## Hosting requirements

PHP 8.2 or newer, PDO MySQL, MySQL/MariaDB, and HTTPS. The upload package needs no build tools. The supplied private-directory protection uses Apache `.htaccess`; another webserver needs equivalent access rules. Back up an existing site before extracting over it.

If the installer reports a database error, check the credentials and that the database user is assigned to the database. If it reports that configuration cannot be written, ask your host to allow the PHP account to write the protected application directory, then retry.

After successful setup, remove `install.php`. The installation lock also prevents it from creating another administrator. Keep configuration, database backups, and operator documents private; packaged guides are under `foodmonster-private/docs/` and can be read locally.

## Later settings

**Account email:** configure your host's PHP `mail()` delivery and sender settings in the generated private configuration before accepting customer signups or using password recovery. Creating and signing in to the first administrator account does not depend on this. No SMTP credential adapter is included.

**Packages:** sign in, open the administrator dashboard, create your actual offers, and publish them. Subscription requests need administrator approval and an expiry date. Approval does not process a payment.

**Restaurant installations:** this package runs the central Food Monster website. Gelato runs separately on each customer's cloud subdomain or own server. Cloud registration reserves a name; the operator configures DNS, TLS, hosting, and Gelato. Self-hosted customers register their hostname and publish the displayed DNS TXT challenge. Verification proves domain control; deployment is confirmed separately.

Payment processing, automated hosting, Gelato distribution, security patching, and script updates remain unconfigured. Test native PHP/MySQL operation, private-file protection, email delivery, and live DNS on your host before accepting customers.
