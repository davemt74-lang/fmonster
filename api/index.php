<?php
declare(strict_types=1);
define('FOODMONSTER_BOOTSTRAP', true);
ini_set('display_errors', '0');

$privateDirectory = is_file(dirname(__DIR__) . '/foodmonster-private/bootstrap.php') ? dirname(__DIR__) . '/foodmonster-private' : dirname(__DIR__) . '/private';
try {
    if (!is_file($privateDirectory . '/bootstrap.php')) { throw new RuntimeException('Application files are missing.'); }
    require $privateDirectory . '/bootstrap.php';
    FMSecurity::headers();
    $config = fm_config();
    FMSecurity::startSession();
    $db = new FMDatabase($config['database']);
    if (!$db->installed()) { throw new FMError(503, 'setup_required', 'Food Monster account services are awaiting operator setup.'); }
    $action = $_GET['action'] ?? 'bootstrap';
    if (!is_string($action) || !preg_match('/^[a-z][a-z_.]{0,63}$/D', $action)) { throw new FMError(404, 'action_not_found', 'This account action does not exist.'); }
    $readActions = ['bootstrap', 'packages.list', 'account.summary', 'admin.overview', 'admin.hosted_jobs', 'admin.hosted_timeline', 'admin.release_analytics'];
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $expectedMethod = in_array($action, $readActions, true) ? 'GET' : 'POST';
    if ($method !== $expectedMethod) { header('Allow: ' . $expectedMethod); throw new FMError(405, 'method_not_allowed', 'Use the supported request method for this action.'); }
    $input = in_array($action, ['admin.hosted_jobs', 'admin.hosted_timeline'], true) ? $_GET : [];
    if ($method === 'POST') { FMSecurity::checkMutation(); }
    if ($action === 'admin.release_upload') {
        $admin = FMSecurity::requireAdmin($db);
        $type = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($type !== 'multipart/form-data') { throw new FMError(415, 'upload_required', 'Choose a ZIP to upload.'); }
        if ($_POST === [] && $_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new FMError(413, 'upload_too_large', 'The upload could not be received. Check the hosting upload limit or stage the ZIP in the private release folder.');
        }
        $data = FMLicensing::uploadRelease($db, $config, $_POST, $_FILES['package'] ?? [], $admin);
    } else {
        if ($method === 'POST') {
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) { throw new FMError(413, 'request_too_large', 'This request is too large.'); }
            $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
            if ($contentType !== 'application/json') { throw new FMError(415, 'json_required', 'Send account requests as JSON.'); }
            $body = file_get_contents('php://input', false, null, 0, 65537);
            if ($body === false || strlen($body) > 65536) { throw new FMError(413, 'request_too_large', 'This request is too large.'); }
            try { $decoded = json_decode($body, false, 16, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new FMError(400, 'invalid_json', 'This request contains invalid JSON.'); }
            if (!$decoded instanceof stdClass) { throw new FMError(400, 'invalid_json', 'Send a JSON object for this request.'); }
            $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        }
        $data = FMAuth::dispatch($action, $input, $db, $config);
        if ($data === null && class_exists('FMCommerce')) { $data = FMCommerce::dispatch($action, $input, $db, $config); }
        if ($data === null) { $data = FMLicensing::dispatch($action, $input, $db, $config); }
        if ($data === null) { $data = FMHostingSettings::dispatch($action, $input, $db, $config); }
        if ($data === null) { $data = FMHostedOperations::dispatch($action, $input, $db); }
    }
    if ($data === null) { throw new FMError(404, 'action_not_found', 'This account action does not exist.'); }
    echo json_encode(['ok' => true, 'data' => (object)$data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (class_exists('FMSecurity', false)) { FMSecurity::headers(); }
    else { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); }
    $expected = $error instanceof FMError;
    http_response_code($expected ? $error->status : 503);
    if (!$expected) { error_log('Food Monster API error: ' . get_class($error)); }
    echo json_encode(['ok' => false, 'error' => ['code' => $expected ? $error->errorCode : 'service_unavailable', 'message' => $expected ? $error->getMessage() : 'Account services are temporarily unavailable. Please try again later.', 'fields' => (object)($expected ? $error->fields : [])]], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
}
