<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

// Reference only: install.php creates config.php and its random application key.
// Normal installation requires no manual configuration or setup key.
// Edit the generated private config.php for later configuration changes.
// Never commit or share the configured file.
return [
    'app_env' => 'production',
    'app_url' => 'https://foodmonster.xyz',
    'base_domain' => 'foodmonster.xyz',

    // Generated automatically by the installer. Keep the generated value private.
    'app_key' => '',

    'database' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'password' => '',
    ],

    'session' => [
        'lifetime' => 28800, // Eight-hour absolute expiry.
        'idle_timeout' => 3600,
        // Optional absolute directory outside web root; PHP must be able to write.
        // Leave blank to use the PHP host's configured session storage.
        'save_path' => '',
    ],

    // Store release ZIPs outside every public document root and web alias.
    'releases' => ['private_directory' => ''],

    // Match the signing secret and API key to the same Stripe account and mode.
    'stripe' => [
        'enabled' => false,
        // Pause new sales while existing Stripe billing and webhooks continue.
        'checkout_enabled' => true,
        'mode' => 'live',
        'secret_key' => '',
        'webhook_secret' => '',
    ],

    // These CLI worker directories are absolute and must already exist. Use
    // separate paths on the same cPanel filesystem. private_root needs mode 0700.
    // Enter the API token through the platform Admin → cPanel form.
    'hosting' => [
        'account_home' => '',
        'tenant_root' => '',
        'private_root' => '',
        'database_host' => 'localhost',
        'database_port' => 3306,
        'managed_updates_enabled' => false, // Enable after a disposable hosted update test.
        // The hosted version is independent of the latest public stable update.
        'release_version' => '',
        'release_public_key' => '', // base64 Ed25519 public key
        'release_signatures' => [], // stable version => base64 detached signature
    ],

    'mail' => [
        // Enable only after configuring and testing the host's PHP mail delivery.
        'enabled' => false,
        'require_verification' => true, // Applies only when email is enabled.
        'transport' => 'mail',
        'from' => 'accounts@foodmonster.xyz',
        'from_name' => 'Food Monster',
        // Test-only capture: app_env=test/development AND localhost app_url required.
        // 'transport' => 'capture', 'capture_path' => '/absolute/private/test-mail',
    ],
];
