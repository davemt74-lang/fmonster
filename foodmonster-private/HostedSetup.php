<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMHostedSetup
{
    public static function profile(array $input, array $owner): array
    {
        $profile = [
            'restaurant_name' => FMAuth::text($input, 'business_name', 145),
            'legal_name' => FMAuth::text($input, 'legal_name', 160, false),
            'location_name' => FMAuth::text($input, 'location_name', 120),
            'first_name' => FMAuth::text($input, 'first_name', 60),
            'last_name' => FMAuth::text($input, 'last_name', 60),
            'timezone' => FMAuth::text($input, 'timezone', 80),
            'email' => $owner['email'],
            'email_verified' => !empty($owner['email_verified_at']),
        ];
        if (!in_array($profile['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new FMError(422, 'invalid_timezone', 'Choose a supported timezone.');
        }
        $password = $input['owner_password'] ?? '';
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")
            || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new FMError(422, 'invalid_owner_password', 'Use a Gelato password of 12–72 bytes with uppercase, lowercase, a number and a symbol.');
        }
        if (($input['owner_password_confirm'] ?? null) !== $password) {
            throw new FMError(422, 'password_mismatch', 'The Gelato passwords do not match.');
        }
        if (password_verify($password, $owner['password_hash'])) {
            throw new FMError(422, 'password_reuse', 'Choose a Gelato password different from your Food Monster account password.');
        }
        return [$profile, FMAuth::hashPassword($password)];
    }

    public static function queue(FMDatabase $db, string $installationId, array $profile, string $hash): void
    {
        $now = time();
        $db->execute('INSERT INTO fm_hosted_setups (installation_id, owner_profile, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$installationId, json_encode($profile, JSON_THROW_ON_ERROR), $hash, $now, $now]);
    }

    public static function decorate(FMDatabase $db, array $items): array
    {
        if (!FMLicensingUpgrade::hostedReady($db)) {
            return $items;
        }
        foreach ($items as &$item) {
            $item['hosted_setup'] = $db->one('SELECT state, stage FROM fm_hosted_setups WHERE installation_id = ?', [$item['id']]);
        }
        unset($item);
        return $items;
    }
}
