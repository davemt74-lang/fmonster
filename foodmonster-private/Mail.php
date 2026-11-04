<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMMail
{
    public static function available(array $config): bool
    {
        $mail = $config['mail'] ?? [];
        if (empty($mail['enabled']) || !filter_var((string)($mail['from'] ?? ''), FILTER_VALIDATE_EMAIL)) { return false; }
        $transport = (string)($mail['transport'] ?? 'mail');
        if ($transport === 'mail') { return function_exists('mail'); }
        if ($transport === 'capture') {
            $host = strtolower((string)parse_url((string)($config['app_url'] ?? ''), PHP_URL_HOST));
            return in_array($config['app_env'] ?? '', ['test', 'development'], true) && in_array($host, ['localhost', '127.0.0.1', '::1', 'terminal.local'], true) && !empty($mail['capture_path']);
        }
        return false;
    }

    public static function send(array $config, string $to, string $kind, string $token): bool
    {
        if (!self::available($config) || !filter_var($to, FILTER_VALIDATE_EMAIL) || !in_array($kind, ['verify', 'reset'], true)) { return false; }
        $path = $kind === 'verify' ? '/verify-email.html#token=' : '/reset-password.html#token=';
        $url = rtrim((string)$config['app_url'], '/') . $path . rawurlencode($token);
        $subject = $kind === 'verify' ? 'Verify your Food Monster email' : 'Reset your Food Monster password';
        $lead = $kind === 'verify' ? 'Confirm this email address to finish setting up your Food Monster account.' : 'A password reset was requested for your Food Monster account.';
        $expiry = $kind === 'verify' ? '24 hours' : '1 hour';
        $text = $lead . "\n\n" . $url . "\n\nThis single-use link expires in {$expiry}. If you did not request this, you can ignore this email.\n\nFood Monster\n";
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><body style="font-family:Arial,sans-serif;color:#202c25;line-height:1.7"><h1 style="font-size:24px">Food Monster</h1><p>' . htmlspecialchars($lead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . ($kind === 'verify' ? 'Verify my email' : 'Reset my password') . '</a></p><p>This single-use link expires in ' . $expiry . '. If you did not request this, you can ignore this email.</p></body></html>';
        return self::deliver($config, $to, $subject, $text, $html);
    }

    public static function lifecycle(array $config, string $to, string $kind, ?string $domain): bool
    {
        if (!self::available($config) || ($config['mail']['lifecycle_enabled'] ?? false) !== true) {
            return false;
        }
        [$subject, $message, $section] = match ($kind) {
            'transfer' => ['A Food Monster subdomain transfer is waiting', "Review the transfer for $domain in My restaurant.", 'restaurant'],
            'renewal_30', 'renewal_7' => ['Your Food Monster subdomain expires soon', "Review the registration for $domain and renew it from My restaurant.", 'restaurant'],
            'billing' => ['Check your Food Monster subscription payment', 'Open your subscription to review the payment issue and manage billing.', 'subscription'],
            'ready' => ['Your Food Monster Cloud site is ready', "Open Cloud setup for the sign-in link to $domain.", 'setup'],
        };
        $url = rtrim((string)$config['app_url'], '/') . '/account.html?section=' . $section;
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = "$message\n\n$url\n\nFood Monster\n";
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><body><h1>Food Monster</h1><p>'
            . $escape($message) . '</p><p><a href="' . $escape($url) . '">Open your account</a></p></body></html>';
        return self::deliver($config, $to, $subject, $text, $html);
    }

    private static function deliver(array $config, string $to, string $subject, string $text, string $html): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
        $mail = $config['mail'];
        $from = (string)$mail['from'];
        $fromName = trim((string)($mail['from_name'] ?? 'Food Monster'));
        if (preg_match('/[\r\n]/', $from . $fromName . $to)) { return false; }
        if (($mail['transport'] ?? '') === 'capture') {
            $directory = rtrim((string)$mail['capture_path'], '/');
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { return false; }
            $path = $directory . '/' . bin2hex(random_bytes(16)) . '.json';
            $written = file_put_contents($path, json_encode(['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html, 'created_at' => time()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
            if ($written !== false) { chmod($path, 0600); return true; }
            return false;
        }
        $boundary = 'fm_' . bin2hex(random_bytes(16));
        $headers = ['From' => $fromName . ' <' . $from . '>', 'MIME-Version' => '1.0', 'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"'];
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($text) . "\r\n--" . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($html) . "\r\n--" . $boundary . "--\r\n";
        return @mail($to, $subject, $body, $headers);
    }
}
