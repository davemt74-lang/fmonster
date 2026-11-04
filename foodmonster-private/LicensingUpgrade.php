<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMLicensingUpgrade
{
    private const KEY = 'licensing_schema_v1';
    private const HOSTED_KEY = 'hosted_setup_schema_v1';
    private const OPERATIONS_KEY = 'hosted_operations_schema_v1';
    private const UPDATES_KEY = 'managed_updates_schema_v1';
    private const COMMERCIAL_KEY = 'commercial_schema_v1';

    public static function ready(FMDatabase $db): bool
    {
        return $db->one('SELECT value_text FROM app_meta WHERE key_name = ?', [self::KEY]) !== null;
    }

    public static function hostedReady(FMDatabase $db): bool
    {
        return $db->one('SELECT value_text FROM app_meta WHERE key_name = ?', [self::HOSTED_KEY]) !== null;
    }

    public static function operationsReady(FMDatabase $db): bool
    {
        return $db->one('SELECT value_text FROM app_meta WHERE key_name = ?', [self::OPERATIONS_KEY]) !== null;
    }

    public static function updatesReady(FMDatabase $db): bool
    {
        return $db->one('SELECT value_text FROM app_meta WHERE key_name = ?', [self::UPDATES_KEY]) !== null;
    }

    public static function commercialSince(FMDatabase $db): ?int
    {
        $row = $db->one('SELECT value_text FROM app_meta WHERE key_name = ?', [self::COMMERCIAL_KEY]);
        return $row === null ? null : (int)$row['value_text'];
    }

    public static function operatorReady(FMDatabase $db): bool
    {
        return $db->one("SELECT value_text FROM app_meta WHERE key_name = 'operator_schema_v1'") !== null;
    }

    public static function apply(FMDatabase $db, string $actor): void
    {
        $migrate = static function () use ($db, $actor): void {
            foreach (['licensing' => self::KEY, 'hosted_setup' => self::HOSTED_KEY, 'hosted_operations' => self::OPERATIONS_KEY, 'managed_updates' => self::UPDATES_KEY, 'commercial' => self::COMMERCIAL_KEY, 'operator' => 'operator_schema_v1'] as $name => $key) {
                if ($db->one('SELECT value_text FROM app_meta WHERE key_name = ?', [$key]) !== null) {
                    continue;
                }
                $sql = file_get_contents(fm_schema_directory() . '/' . $name . '.' . $db->driver . '.sql');
                if ($sql === false) {
                    throw new RuntimeException('Platform upgrade schema file is missing.');
                }
                // Additive MySQL DDL can resume after any completed CREATE.
                foreach (explode(';', $sql) as $statement) {
                    if (trim($statement) !== '') {
                        $db->pdo->exec($statement);
                    }
                }
                $db->transaction(static function () use ($db, $actor, $key, $name): void {
                    $db->execute('INSERT INTO app_meta (key_name, value_text) VALUES (?, ?)', [$key, (string)time()]);
                    FMSecurity::audit($db, $actor, 'platform.' . $name . '_schema_upgraded');
                });
            }
        };

        if ($db->driver === 'sqlite') {
            $db->transaction($migrate);
            return;
        }

        $lock = 'fm-licensing-' . substr(hash('sha256', (string)$db->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
        if ((int)$db->one('SELECT GET_LOCK(?, 5) AS acquired', [$lock])['acquired'] !== 1) {
            throw new FMError(409, 'upgrade_busy', 'Another database upgrade is running. Refresh the page and try again.');
        }
        try {
            $migrate();
        } finally {
            $db->one('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
