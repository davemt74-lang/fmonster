<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FOODMONSTER_BOOTSTRAP', true);
require __DIR__ . '/bootstrap.php';

try {
    $config = fm_config();
    $db = new FMDatabase($config['database']);
    if (($argv[1] ?? '') === '--status') {
        if (FMLicensingUpgrade::lifecycleSince($db) === null) {
            throw new FMError(503, 'upgrade_required', 'Run the database upgrade first.');
        }
        $result = $db->query('SELECT kind, state, COUNT(*) AS total, MIN(due_at) AS oldest FROM fm_lifecycle_notices GROUP BY kind, state');
    } else {
        $result = FMLifecycleNotices::run($db, $config);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
    exit(empty($result['failed']) ? 0 : 1);
} catch (FMError $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Lifecycle worker error (' . get_class($error) . '). Review worker status and mail delivery.' . PHP_EOL);
    exit(1);
}
