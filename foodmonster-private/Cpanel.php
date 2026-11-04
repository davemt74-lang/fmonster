<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMCpanel
{
    private const ENDPOINTS = [
        'server_policy' => 'Variables/get_server_information',
        'resource_usage' => 'ResourceUsage/get_usages',
        'autossl_status' => 'SSL/get_autossl_renewal_status',
        'domains' => 'DomainInfo/list_domains',
        'domain' => 'DomainInfo/single_domain_data',
        'add_subdomain' => 'SubDomain/addsubdomain',
        'databases' => 'Mysql/list_databases',
        'users' => 'Mysql/list_users',
        'add_database' => 'Mysql/create_database',
        'add_user' => 'Mysql/create_user',
        'grant' => 'Mysql/set_privileges_on_database',
    ];

    public function __construct(private readonly array $connection, private readonly array $config)
    {
    }

    public static function hostname(string $host, array $config): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $suffixes = $config['hosting']['cpanel_host_suffixes'] ?? ['hostgator.com', 'websitewelcome.com'];
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && is_array($suffixes)) {
            foreach ($suffixes as $suffix) {
                if (is_string($suffix) && $suffix !== '' && str_ends_with($host, '.' . $suffix)) {
                    return $host;
                }
            }
        }
        throw new FMError(422, 'invalid_cpanel_host', 'Use the approved cPanel server hostname without https://, a port, or a path.');
    }

    public function assertOwnsDomain(string $domain): void
    {
        $data = $this->call('domains');
        $domains = array_merge([(string)($data['main_domain'] ?? '')], (array)($data['addon_domains'] ?? []));
        if (!in_array($domain, $domains, true)) {
            throw new FMError(409, 'cpanel_domain_missing', 'The configured Food Monster parent domain is not an owned main or addon domain in this account.');
        }
    }

    public function assertDomainUnused(string $parent, string $domain): void
    {
        $data = $this->call('domains');
        $main = $data['main_domain'] ?? null;
        if (!is_string($main) || $main === '') {
            throw new FMError(409, 'retirement_inventory_unavailable', 'cPanel did not return a complete domain inventory.');
        }
        $groups = ['main_domain' => [$main]];
        foreach (['addon_domains', 'parked_domains', 'sub_domains'] as $group) {
            $list = $data[$group] ?? null;
            if (!is_array($list) || !array_is_list($list) || count(array_filter($list, 'is_string')) !== count($list)) {
                throw new FMError(409, 'retirement_inventory_unavailable', 'cPanel did not return a complete domain inventory.');
            }
            $groups[$group] = $list;
        }
        $normal = static fn(string $name): string => strtolower(rtrim($name, '.'));
        if (!in_array($parent, array_map($normal, [$main, ...$groups['addon_domains']]), true)) {
            throw new FMError(409, 'cpanel_domain_missing', 'The configured parent domain is not owned by the saved cPanel account.');
        }
        if (in_array($domain, array_map($normal, array_merge(...array_values($groups))), true)) {
            throw new FMError(409, 'retirement_domain_present', 'cPanel still lists this hostname. Review the active domain in cPanel.');
        }
    }

    public function call(string $operation, array $parameters = []): array
    {
        $endpoint = self::ENDPOINTS[$operation] ?? null;
        if ($endpoint === null) {
            throw new InvalidArgumentException('Unsupported hosting operation.');
        }
        $host = self::hostname($this->connection['hostname'], $this->config);
        $transport = ($this->config['app_env'] ?? '') === 'test' ? ($this->config['hosting']['test_cpanel_transport'] ?? null) : null;
        if (is_callable($transport)) {
            $response = $transport($operation, $parameters, $this->connection);
        } else {
            $response = $this->request($host, $endpoint, $operation, $parameters);
        }
        if (!is_array($response) || (int)($response['result']['status'] ?? 0) !== 1) {
            throw new FMError(409, 'cpanel_request_rejected', 'cPanel rejected the hosting request. Check the API token, account permissions, and hosting limits.');
        }
        return is_array($response['result']['data'] ?? null) ? $response['result']['data'] : [];
    }

    private function request(string $host, string $endpoint, string $operation, array $parameters): array
    {
        if (!function_exists('curl_init')) {
            throw new FMError(503, 'curl_required', 'Enable PHP cURL before using cPanel hosting.');
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $address = null;
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                $address = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
                break;
            }
        }
        if ($address === null) {
            throw new FMError(503, 'cpanel_dns_unavailable', 'The cPanel server hostname has no reachable public address.');
        }
        $write = in_array($operation, ['add_subdomain', 'add_database', 'add_user', 'grant'], true);
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $url = 'https://' . $host . ':2083/execute/' . $endpoint;
        $curl = curl_init($url . (!$write && $query !== '' ? '?' . $query : ''));
        if ($curl === false) {
            throw new RuntimeException('The cPanel connection could not be initialized.');
        }
        $body = '';
        $preflight = in_array($operation, ['server_policy', 'resource_usage', 'autossl_status'], true);
        try {
            curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: cpanel ' . $this->connection['username'] . ':' . $this->connection['token']],
                CURLOPT_RESOLVE => [$host . ':2083:' . $address],
                CURLOPT_PROXY => '',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $preflight ? 6 : 25,
                CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 1048576) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($write) {
                curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $query]);
            }
            $result = curl_exec($curl);
            $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (in_array($http, [401, 403], true)) {
                throw new FMError(409, 'cpanel_auth_failed', 'cPanel denied the request. Check the saved token and account permissions.');
            }
            if (($result === false && in_array(curl_errno($curl), [CURLE_SSL_PEER_CERTIFICATE, CURLE_WRITE_ERROR], true))
                || ($http >= 300 && $http < 500 && $http !== 429)) {
                throw new FMError(409, 'cpanel_request_rejected', 'cPanel rejected the secure request. Check the host, TLS certificate, and account permissions.');
            }
            if ($result === false || $http !== 200) {
                throw new FMError(503, 'cpanel_connection_failed', 'Could not connect to cPanel over HTTPS. Check the hostname and outbound access to port 2083.');
            }
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        } finally {
            curl_close($curl);
        }
    }
}
