<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMHostedReleaseBundle
{
    private const MEMBERS = [
        'gelato-hosted.zip' => 536870912,
        'gelato-hosted.sha256' => 128,
        'gelato-hosted.sig' => 128,
        'release-notes.txt' => 40001,
        'release.json' => 4096,
    ];

    public static function unpack(array $config, string $source, $output): array
    {
        $zip = new ZipArchive();
        if ($zip->open($source, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw self::invalid();
        }
        try {
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'] ?? '';
                if (!isset(self::MEMBERS[$name]) || isset($seen[$name]) || $entry['size'] < 1
                    || $entry['size'] > self::MEMBERS[$name]
                    || !$zip->getExternalAttributesIndex($i, $system, $attributes)
                    || ($system === ZipArchive::OPSYS_UNIX && !in_array(($attributes >> 16) & 0170000, [0, 0100000], true))) {
                    throw self::invalid();
                }
                $seen[$name] = $entry['size'];
            }
            if (count($seen) !== count(self::MEMBERS)) {
                throw self::invalid();
            }
            $meta = json_decode(self::read($zip, 'release.json'), true, 8);
            $version = $meta['version'] ?? null;
            $sha = $meta['sha256'] ?? null;
            $commit = $meta['source_commit'] ?? null;
            $fingerprint = $meta['public_key_sha256'] ?? null;
            if (!is_array($meta) || !is_string($version) || !preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.(?:0|[1-9][0-9]{0,8})){2}$/D', $version)
                || ($meta['channel'] ?? null) !== 'stable' || ($meta['package'] ?? null) !== 'gelato-hosted.zip'
                || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)
                || !is_string($commit) || !preg_match('/^[a-f0-9]{40}$/D', $commit)
                || !is_string($fingerprint) || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)
                || trim(self::read($zip, 'gelato-hosted.sha256')) !== "$sha  gelato-hosted.zip") {
                throw self::invalid();
            }
            $signature = trim(self::read($zip, 'gelato-hosted.sig'));
            FMLicensing::assertHostedSignature($config, $version, $sha, $signature);
            $key = base64_decode((string)$config['hosting']['release_public_key'], true);
            if (!hash_equals($fingerprint, hash('sha256', $key))) {
                throw new FMError(409, 'hosted_signer_mismatch', 'The bundle fingerprint differs from the configured signing key.');
            }
            $notes = self::read($zip, 'release-notes.txt');
            $input = $zip->getStream('gelato-hosted.zip');
            if ($input === false) {
                throw self::invalid();
            }
            try {
                if (stream_copy_to_stream($input, $output, $seen['gelato-hosted.zip'] + 1) !== $seen['gelato-hosted.zip'] || !fflush($output)) {
                    throw self::invalid();
                }
            } finally {
                fclose($input);
            }
            return ['version' => $version, 'sha256' => $sha, 'source_commit' => $commit, 'signature' => $signature,
                'public_key_sha256' => $fingerprint, 'notes' => $notes];
        } finally {
            $zip->close();
        }
    }

    public static function assertIdentity(string $path, string $commit): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw self::invalid();
        }
        try {
            if (trim((string)$zip->getFromName('DEPLOY_SHA.txt', 100)) !== $commit
                || $zip->locateName('scripts/food-monster-provision.php') === false
                || $zip->locateName('food-monster-hosted-health.php') === false) {
                throw new FMError(422, 'hosted_bundle_identity', 'The signed ZIP is missing the hosted setup files or the source commit differs from the manifest.');
            }
        } finally {
            $zip->close();
        }
    }

    private static function read(ZipArchive $zip, string $name): string
    {
        $value = $zip->getFromName($name, self::MEMBERS[$name] + 1);
        if (!is_string($value)) {
            throw self::invalid();
        }
        return $value;
    }

    private static function invalid(): FMError
    {
        return new FMError(422, 'invalid_hosted_bundle', 'Choose the original signed Gelato release artifact ZIP with its five files at the root.');
    }
}
