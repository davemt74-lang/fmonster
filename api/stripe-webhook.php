<?php
declare(strict_types=1);
define('FOODMONSTER_BOOTSTRAP', true);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    require dirname(__DIR__) . '/foodmonster-private/bootstrap.php';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new FMError(405, 'method_not_allowed', 'Use POST for Stripe webhooks.');
    }
    $body = file_get_contents('php://input', false, null, 0, 1048577);
    $signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    if ($body === false || strlen($body) > 1048576 || strlen($signature) > 4096) {
        throw new FMError(413, 'event_too_large', 'The Stripe event is too large.');
    }
    $config = fm_config();
    FMStripeBilling::receive(new FMDatabase($config['database']), $config, $body, $signature);
    echo '{"ok":true}';
} catch (Throwable $error) {
    $expected = $error instanceof FMError;
    http_response_code($expected ? $error->status : 503);
    if (!$expected) {
        error_log('Food Monster Stripe webhook failed: ' . get_class($error));
    }
    echo json_encode(['ok' => false, 'error' => $expected ? $error->errorCode : 'webhook_unavailable']);
}
