<?php
declare(strict_types=1);
define('FOODMONSTER_BOOTSTRAP', true);
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$privateDirectory = is_file(__DIR__ . '/foodmonster-private/bootstrap.php') ? __DIR__ . '/foodmonster-private' : __DIR__ . '/private';
$ready = false;
$errorMessage = null;
$csrf = '';
try {
    require $privateDirectory . '/bootstrap.php';
    $config = fm_config();
    FMSecurity::startSession();
    $db = new FMDatabase($config['database']);
    if (!$db->installed()) {
        throw new FMError(503, 'setup_required', 'An installed Food Monster database is required.');
    }
    $admin = FMSecurity::requireAdmin($db);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new FMError(405, 'method_not_allowed', 'Use the upgrade form.');
    }
    $csrf = FMSecurity::csrf();
    if ($method === 'POST') {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
        FMSecurity::checkMutation();
        FMLicensingUpgrade::apply($db, $admin['id']);
    }
    $ready = FMLicensingUpgrade::operatorReady($db);
} catch (Throwable $error) {
    $expected = $error instanceof FMError;
    http_response_code($expected ? $error->status : 503);
    $errorMessage = $expected ? $error->getMessage() : 'The upgrade could not finish. Check database permissions and the server error log, then retry.';
    if (!$expected) {
        error_log('Food Monster licensing upgrade: ' . get_class($error) . ' (' . $error->getCode() . ')');
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="portal.css">
<title>Upgrade Food Monster</title>
</head>
<body>
<main class="plans-main">
    <section class="panel">
        <h1>Food Monster database upgrade</h1>
        <?php if ($errorMessage !== null): ?>
            <p role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($csrf === ''): ?>
            <p><a href="login.html?next=admin">Sign in as an administrator</a>, then reopen this page.</p>
        <?php elseif ($ready): ?>
            <p>Licensing and the release registry are ready. Hosted setup, billing, and annual subdomain registration are available.</p>
            <a class="button primary" href="admin.html?section=licenses">Open licensing</a>
        <?php else: ?>
            <p>This upgrade adds billing and subdomain registration tables alongside any missing licensing, release, analytics, and hosting tables.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <button class="button primary" type="submit">Run database upgrade</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
