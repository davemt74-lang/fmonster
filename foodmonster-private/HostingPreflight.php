<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMHostingPreflight
{
    public static function paths(array $config): array
    {
        $hosting = $config['hosting'] ?? [];
        $paths = [];
        foreach (['account_home', 'tenant_root', 'private_root'] as $key) {
            $path = $hosting[$key] ?? null;
            if (!is_string($path) || !str_starts_with($path, '/') || $path === '/' || realpath($path) !== $path || !is_dir($path)) {
                throw new FMError(409, 'hosting_paths_invalid', 'Configure canonical existing cPanel filesystem directories.');
            }
            $paths[] = $path;
        }
        [$home, $tenant, $private] = $paths;
        $app = dirname(__DIR__);
        foreach ([$tenant, $private] as $root) {
            if (!str_starts_with($root, $home . '/') || self::overlap($root, $app) || !is_writable($root)) {
                throw new FMError(409, 'hosting_paths_invalid', 'Use writable hosting directories under the cPanel home and outside the portal document root.');
            }
        }
        $public = realpath($home . '/public_html') ?: $home . '/public_html';
        if (self::overlap($tenant, $private) || self::overlap($private, $public)
            || (fileperms($private) & 0777) !== 0700 || stat($tenant)['dev'] !== stat($private)['dev']) {
            throw new FMError(409, 'hosting_paths_invalid', 'Use separate hosting directories on one filesystem and keep the mode-0700 private directory outside public_html.');
        }
        return $paths;
    }

    public static function parentRoot(FMCpanel $cpanel, string $domain, string $tenant, string $private): void
    {
        $root = $cpanel->call('domain', ['domain' => $domain])['documentroot'] ?? null;
        if (!is_string($root) || !str_starts_with($root, '/')) {
            throw new FMError(409, 'parent_root_unverified', 'cPanel did not report the parent domain’s document root.');
        }
        $root = realpath($root) ?: rtrim($root, '/');
        if ($root === '' || self::overlap($tenant, $root) || self::overlap($private, $root)) {
            throw new FMError(409, 'parent_root_conflict', 'Keep the tenant and private directories separate from the parent domain’s document root.');
        }
    }

    public static function inspect(FMDatabase $db, array $config): array
    {
        $checks = [];
        $add = static function (string $label, string $status, string $detail) use (&$checks): void {
            $checks[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        };
        $paths = null;
        try {
            $paths = self::paths($config);
            $add('Hosting directories', 'pass', 'The directories are writable, separate, and on the same filesystem.');
        } catch (FMError $error) {
            $add('Hosting directories', 'fail', $error->getMessage());
        }

        $missing = array_values(array_filter(['curl', 'pdo_mysql', 'sodium', 'zip', 'mbstring'], static fn(string $name): bool => !extension_loaded($name)));
        if (!function_exists('proc_open')) {
            $missing[] = 'proc_open';
        }
        if (PHP_VERSION_ID < 80200) {
            $missing[] = 'PHP 8.2 or newer';
        }
        $cli = PHP_SAPI === 'cli';
        $add($cli ? 'Worker PHP' : 'Portal PHP', $missing === [] ? 'pass' : ($cli ? 'fail' : 'review'),
            $missing === [] ? 'The required extensions are available in this PHP runtime.' : 'This PHP runtime is missing ' . implode(', ', $missing) . '.');
        if (!$cli) {
            $add('Cron PHP', 'review', 'Run the CLI preflight with the same PHP command used by cron.');
        }

        try {
            $connection = FMHostingSettings::connection($db, $config);
            $cpanel = new FMCpanel($connection, $config);
            $cpanel->assertOwnsDomain((string)$config['base_domain']);
            $add('cPanel access', empty($connection['tested_at']) ? 'review' : 'pass', empty($connection['tested_at'])
                ? 'Parent domain ownership verified. Save the connection test before using the worker.'
                : 'The saved cPanel account owns the parent domain.');
            self::accountChecks($cpanel, $config, $connection, $paths, $add);
        } catch (FMError $error) {
            $add('cPanel access', 'fail', $error->getMessage());
        } catch (Throwable) {
            $add('cPanel access', 'fail', 'The saved connection could not be checked. Review the private configuration and cPanel token.');
        }

        try {
            [, $version, $file] = FMLicensing::hostedPackage($db, $config, null);
            $zip = new ZipArchive();
            if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Hosted package could not be opened.');
            }
            try {
                if ($zip->locateName('scripts/food-monster-provision.php') === false
                    || $zip->locateName('database/install.sql') === false || $zip->locateName('food-monster-hosted-health.php') === false) {
                    throw new FMError(409, 'hosted_release_incomplete', 'The signed release is missing the hosted installer or health check.');
                }
            } finally {
                $zip->close();
            }
            $add('Hosted release', 'pass', 'The signed Gelato ' . $version . ' hosted package is published and readable.');
        } catch (FMError $error) {
            $add('Hosted release', 'fail', $error->getMessage());
        } catch (Throwable) {
            $add('Hosted release', 'fail', 'The signed hosted release could not be checked in this PHP runtime.');
        }
        return ['checked_at' => time(), 'checks' => $checks];
    }

    private static function accountChecks(FMCpanel $cpanel, array $config, array $connection, ?array $paths, callable $add): void
    {
        $domain = (string)$config['base_domain'];
        try {
            if ($paths !== null) {
                self::parentRoot($cpanel, $domain, $paths[1], $paths[2]);
            }
            $policy = $cpanel->call('server_policy', ['name-1' => 'docroots_in_public_html_only', 'name-2' => 'database_prefix_required']);
            $restricted = $policy['docroots_in_public_html_only'] ?? null;
            $prefix = $policy['database_prefix_required'] ?? null;
            if ($paths !== null && self::enabled($restricted) === true && !str_starts_with($paths[1], $paths[0] . '/public_html/')) {
                $add('Document root policy', 'fail', 'cPanel requires new document roots under public_html. The configured tenant directory is outside it.');
            } elseif ($paths !== null && self::enabled($restricted) !== null) {
                $add('Document root policy', 'pass', 'The configured layout matches the reported cPanel directory policy.');
            } else {
                $add('Document root policy', 'review', 'Confirm that cPanel allows the tenant directory layout.');
            }
            if (self::enabled($prefix) === true && $connection['database_prefix'] === '') {
                $add('MySQL naming', 'fail', 'cPanel requires a database prefix. Save the prefix shown in cPanel.');
            } elseif (self::enabled($prefix) === null || (self::enabled($prefix) === false && $connection['database_prefix'] !== '')) {
                $add('MySQL naming', 'review', 'Confirm the configured database and user prefix in cPanel.');
            } else {
                $add('MySQL naming', 'pass', 'The configured prefix agrees with the reported requirement.');
            }
        } catch (FMError $error) {
            $add('Document root policy', $error->errorCode === 'parent_root_conflict' ? 'fail' : 'review',
                $error->errorCode === 'parent_root_conflict' ? $error->getMessage() : 'cPanel could not verify its document-root and database-prefix policies.');
        }

        try {
            $usage = $cpanel->call('resource_usage');
            foreach (['mysql_databases' => 'Database quota', 'subdomains' => 'Subdomain quota'] as $id => $label) {
                $used = $limit = null;
                foreach ($usage as $row) {
                    if (is_array($row) && ($row['id'] ?? null) === $id) {
                        $used = self::countValue($row['usage'] ?? null);
                        $limit = self::countValue($row['maximum'] ?? null);
                        break;
                    }
                }
                if ($used !== null && $limit !== null) {
                    $add($label, $used >= $limit ? 'fail' : 'pass', $used . ' of ' . $limit . ' in use.');
                } else {
                    $add($label, 'review', 'A numeric quota was unavailable. Confirm capacity in cPanel.');
                }
            }
        } catch (FMError) {
            $add('Account quotas', 'review', 'The cPanel account did not provide resource usage.');
        }

        try {
            $ssl = $cpanel->call('autossl_status', ['domain' => $domain]);
            $active = self::enabled($ssl['is_active'] ?? null);
            $excluded = self::enabled($ssl['is_excluded'] ?? null);
            $problems = self::enabled($ssl['has_problems'] ?? null);
            $clear = $active === true && $excluded === false && $problems === false;
            $add('Parent AutoSSL', $clear ? 'pass' : 'review', $clear
                ? 'AutoSSL is active and cPanel reports no exclusion or validation problem for the parent domain. Each tenant still needs its own HTTPS check.'
                : 'Review AutoSSL availability, exclusions, and validation in cPanel. Each tenant still needs its own HTTPS check.');
        } catch (FMError) {
            $add('Parent AutoSSL', 'review', 'The cPanel account did not provide AutoSSL status.');
        }
    }

    private static function overlap(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/');
    }

    private static function enabled(mixed $value): ?bool
    {
        return match ($value) {
            1, '1', true => true,
            0, '0', false => false,
            default => null,
        };
    }

    private static function countValue(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $parsed === false ? null : $parsed;
    }
}
