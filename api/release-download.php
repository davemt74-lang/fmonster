<?php
declare(strict_types=1);
define('FOODMONSTER_BOOTSTRAP', true);
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    $root = dirname(__DIR__);
    require (is_file($root . '/foodmonster-private/bootstrap.php') ? $root . '/foodmonster-private' : $root . '/private') . '/bootstrap.php';
    $config = fm_config();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new FMError(405, 'method_not_allowed', 'Approve the release from the Food Monster portal.');
    }
    FMSecurity::startSession();
    $db = new FMDatabase($config['database']);
    $actor = FMSecurity::requireUser($db);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    FMSecurity::checkMutation();
    session_write_close();
    [$handle, $bytes, $filename, $attempt] = FMLicensing::manualDownload($db, $config, $_POST, $actor);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $bytes);
    header('X-Food-Monster-Update-Attempt: ' . $attempt);
    $served = fpassthru($handle);
    fclose($handle);
    flush();
    if ($served === $bytes && connection_status() === CONNECTION_NORMAL) {
        try {
            FMManagedUpdates::recordDownload($db, $attempt);
        } catch (Throwable $error) {
            error_log('Food Monster could not record a served release download.');
        }
    }
} catch (Throwable $error) {
    $expected = $error instanceof FMError;
    http_response_code($expected ? $error->status : 503);
    if (!$expected) {
        error_log('Food Monster release download: ' . get_class($error) . ' (' . $error->getCode() . ')');
    }
    echo $expected ? $error->getMessage() : 'The release could not be downloaded. Try again from the portal.';
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
