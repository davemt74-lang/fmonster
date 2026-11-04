<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }
if (!defined('FM_BOOTSTRAPPED')) { define('FM_BOOTSTRAPPED', true); }

ini_set('display_errors', '0');
date_default_timezone_set('UTC');
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Mail.php';
require_once __DIR__ . '/LifecycleNotices.php';
require_once __DIR__ . '/Auth.php';
if (is_file(__DIR__ . '/Commerce.php')) { require_once __DIR__ . '/Commerce.php'; }

require_once __DIR__ . '/LicensingUpgrade.php';
require_once __DIR__ . '/Licensing.php';
require_once __DIR__ . '/HostedReleaseBundle.php';
require_once __DIR__ . '/HostedSetup.php';
require_once __DIR__ . '/Cpanel.php';
require_once __DIR__ . '/HostingSettings.php';
require_once __DIR__ . '/HostingPreflight.php';
require_once __DIR__ . '/HostedOperations.php';
require_once __DIR__ . '/ManagedUpdates.php';
require_once __DIR__ . "/Subdomains.php";
require_once __DIR__ . "/StripeBilling.php";

require_once __DIR__ . '/OperatorDashboard.php';

function fm_config_path(): string
{
    return defined('FOODMONSTER_CONFIG_PATH') ? (string)FOODMONSTER_CONFIG_PATH : (getenv('FOODMONSTER_CONFIG') ?: __DIR__ . '/config.php');
}

function fm_config(): array
{
    $path = fm_config_path();
    if (!is_file($path)) { throw new FMError(503, 'setup_required', 'Food Monster account services are awaiting operator setup.'); }
    $config = require $path;
    if (!is_array($config)) { throw new FMError(503, 'configuration_invalid', 'Food Monster account services need operator configuration.'); }
    $config += ['app_env' => 'production', 'app_url' => '', 'app_key' => '', 'base_domain' => 'foodmonster.xyz', 'database' => [], 'session' => [], 'mail' => []];
    if (!is_array($config['database']) || !is_array($config['session']) || !is_array($config['mail'])) { throw new FMError(503, 'configuration_invalid', 'Food Monster account services need operator configuration.'); }
    $url = parse_url((string)$config['app_url']);
    $environment = (string)$config['app_env'];
    $localHost = is_array($url) && in_array(strtolower((string)($url['host'] ?? '')), ['localhost', '127.0.0.1', '::1', 'terminal.local'], true);
    if (!in_array($environment, ['production', 'development', 'test'], true) || !is_array($url) || empty($url['host']) || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ($environment === 'production' && ($url['scheme'] ?? '') !== 'https') || ($environment !== 'production' && !$localHost) || strlen((string)$config['app_key']) < 64 || preg_match('/[\r\n]/', (string)$config['app_url'])) {
        throw new FMError(503, 'configuration_invalid', 'Food Monster account services need operator configuration.');
    }
    if (($config['database']['driver'] ?? 'mysql') === 'sqlite' && $environment === 'production') { throw new FMError(503, 'configuration_invalid', 'Production account services require a configured MySQL database.'); }
    $config['app_url'] = rtrim((string)$config['app_url'], '/');
    $config['session'] += ['lifetime' => 28800, 'idle_timeout' => 3600];
    if ((int)$config['session']['lifetime'] < 300 || (int)$config['session']['lifetime'] > 604800 || (int)$config['session']['idle_timeout'] < 60 || (int)$config['session']['idle_timeout'] > (int)$config['session']['lifetime']) { throw new FMError(503, 'configuration_invalid', 'Food Monster session settings need operator configuration.'); }
    FMSecurity::configure($config);
    return $config;
}

function fm_schema_directory(): string
{
    return is_dir(__DIR__ . '/schema') ? __DIR__ . '/schema' : dirname(__DIR__) . '/schema';
}
