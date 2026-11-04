<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FOODMONSTER_BOOTSTRAP', true);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/HostedWorker.php';

try {
    $config = fm_config();
    $db = new FMDatabase($config['database']);
    $target = $argv[1] ?? '';
    if ($target === '--preflight') {
        $report = FMHostingPreflight::inspect($db, $config);
        foreach ($report['checks'] as $check) {
            echo strtoupper($check['status']), '  ', $check['label'], '  ', $check['detail'], PHP_EOL;
        }
        exit(in_array('fail', array_column($report['checks'], 'status'), true) ? 1 : 0);
    }
    $worker = new FMHostedWorker($db, $config);
    if (in_array($target, ['--cancel', '--release-cancelled'], true)) {
        echo $worker->cancel($argv[2] ?? '', $target === '--release-cancelled'), PHP_EOL;
        exit;
    }
    if ($target === '--recover-update') {
        $result = $worker->runUpdate($argv[2] ?? '', true);
        echo $result, PHP_EOL;
        exit($result === 'recovery' ? 1 : 0);
    }
    if ($target === '--next') {
        foreach (FMManagedUpdates::due($db) as $update) {
            try {
                $result = $worker->runUpdate($update['attempt_id']);
            } catch (FMError $error) {
                if ($error->errorCode === 'worker_busy') {
                    continue;
                }
                throw $error;
            }
            if ($result !== 'skipped') {
                echo 'update ', $result, PHP_EOL;
                exit(in_array($result, ['failed', 'recovery'], true) ? 1 : 0);
            }
        }
        foreach ((new FMHostedOperations($db))->due() as $id) {
            try {
                $result = $worker->run($id, true);
            } catch (FMError $error) {
                if ($error->errorCode === 'worker_busy') {
                    continue;
                }
                throw $error;
            }
            if ($result !== 'skipped') {
                echo $result, PHP_EOL;
                exit($result === 'failed' ? 1 : 0);
            }
        }
        exit;
    }
    $result = $worker->run($target);
    echo $result, PHP_EOL;
    exit($result === 'failed' ? 1 : 0);
} catch (FMError $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Hosted setup failed (' . get_class($error) . '). Check the configured directories, cPanel account, and Gelato release.' . PHP_EOL);
    exit(1);
}
