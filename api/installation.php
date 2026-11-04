<?php
declare(strict_types=1);
define('FOODMONSTER_BOOTSTRAP', true);
ini_set('display_errors', '0');

try {
    $root = dirname(__DIR__);
    require (is_file($root . '/foodmonster-private/bootstrap.php') ? $root . '/foodmonster-private' : $root . '/private') . '/bootstrap.php';
    FMSecurity::headers();
    $config = fm_config();
    if ($config['app_env'] === 'production' && !FMSecurity::isHttpsRequest()) {
        throw new FMError(403, 'https_required', 'Installation requests require HTTPS.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new FMError(405, 'method_not_allowed', 'Use POST for installation requests.');
    }
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        throw new FMError(415, 'json_required', 'Send an installation request as JSON.');
    }
    $body = file_get_contents('php://input', false, null, 0, 8193);
    if ($body === false || strlen($body) > 8192) {
        throw new FMError(413, 'request_too_large', 'This request is too large.');
    }
    try {
        $decoded = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new FMError(400, 'invalid_json', 'Send a valid JSON object.');
    }
    if (!$decoded instanceof stdClass) {
        throw new FMError(400, 'invalid_json', 'Send a valid JSON object.');
    }
    $input = (array)$decoded;
    $action = $input['action'] ?? null;
    if (!in_array($action, ['activate', 'status', 'heartbeat', 'report'], true)) {
        throw new FMError(404, 'action_not_found', 'This installation action does not exist.');
    }
    $credential = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $db = new FMDatabase($config['database']);
    if (!$db->installed()) {
        throw new FMError(503, 'setup_required', 'The Food Monster platform is unavailable.');
    }
    FMSecurity::rateLimit($db, 'installation_ip:' . FMSecurity::clientIp(), 600, 300);
    $data = FMLicensing::server($db, $action, $input, $credential);
    echo json_encode(['ok' => true, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (class_exists('FMSecurity', false)) {
        FMSecurity::headers();
    }
    $expected = $error instanceof FMError;
    http_response_code($expected ? $error->status : 503);
    if (!$expected) {
        error_log('Food Monster installation API: ' . get_class($error) . ' (' . $error->getCode() . ')');
    }
    echo json_encode(['ok' => false, 'error' => [
        'code' => $expected ? $error->errorCode : 'service_unavailable',
        'message' => $expected ? $error->getMessage() : 'Food Monster licensing is temporarily unavailable.',
    ]], JSON_INVALID_UTF8_SUBSTITUTE);
}
