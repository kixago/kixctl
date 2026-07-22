<?php

namespace App\Console\Commands;

use App\Services\Licensing\InvalidLicenseException;
use App\Services\Licensing\LicenseVerifier;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Issue (sign) a kixctl license. VENDOR-SIDE tooling — requires the
 * Ed25519 secret key, which never ships. Round-trips the result through
 * the configured verifier when a public key is set, so a bad keypair is
 * caught at issue time, not at the customer.
 */
class LicenseIssue extends Command
{
    protected $signature = 'license:issue
        {--key=storage/license/secret.key : Path to the base64 secret key file}
        {--licensee= : Person or org the license is issued to}
        {--email= : Contact email (optional)}
        {--tier=fleet : License tier}
        {--max-clusters=5 : Endpoint rows allowed}
        {--expires= : Expiry date (ISO-8601, UTC assumed) — omit for perpetual}
        {--out= : Also write the license to this file}';

    protected $description = 'Sign a kixctl license file (vendor-side)';

    public function handle(): int
    {
        $licensee = trim((string) $this->option('licensee'));

        if ($licensee === '') {
            $this->error('--licensee is required.');

            return self::FAILURE;
        }

        $keyPath = base_path($this->option('key'));

        if (! is_file($keyPath)) {
            $this->error("Secret key not found at {$keyPath} (run license:keys first).");

            return self::FAILURE;
        }

        $secret = base64_decode(trim((string) file_get_contents($keyPath)), strict: true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('Secret key file does not contain a valid base64 Ed25519 secret key.');

            return self::FAILURE;
        }

        $maxClusters = (int) $this->option('max-clusters');

        if ($maxClusters < 1) {
            $this->error('--max-clusters must be a positive integer.');

            return self::FAILURE;
        }

        $expiresAt = null;

        if ($this->option('expires')) {
            try {
                $expiresAt = new DateTimeImmutable($this->option('expires'), new DateTimeZone('UTC'));
            } catch (\Exception $_e) {
                $this->error('--expires is not a parseable date.');

                return self::FAILURE;
            }
        }

        $payload = [
            'v' => LicenseVerifier::VERSION,
            'id' => 'lic_'.Str::lower(Str::random(20)),
            'licensee' => $licensee,
            'email' => $this->option('email') ?: null,
            'tier' => (string) $this->option('tier'),
            'max_clusters' => $maxClusters,
            'issued_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'expires_at' => $expiresAt?->format(DATE_ATOM),
        ];

        $license = LicenseVerifier::sign($payload, $secret);

        // Round-trip through the verifier if a public key is configured —
        // catches a mismatched keypair at issue time.
        $publicKey = (string) config('license.public_key');

        if ($publicKey !== '') {
            try {
                (new LicenseVerifier($publicKey))->verify($license);
                $this->info('Round-trip verification against the configured public key: OK.');
            } catch (InvalidLicenseException $e) {
                $this->error("Issued license does NOT verify against the configured public key: {$e->getMessage()}");
                $this->error('The secret key and KIXCTL_LICENSE_PUBLIC_KEY are probably from different keypairs.');

                return self::FAILURE;
            }
        } else {
            $this->warn('No public key configured — skipping round-trip verification.');
        }

        $this->newLine();
        $this->line($license);

        if ($this->option('out')) {
            $outPath = $this->option('out');
            $outPath = str_starts_with($outPath, '/') ? $outPath : base_path($outPath);

            if (file_put_contents($outPath, $license.PHP_EOL) === false) {
                $this->error("Could not write license to {$outPath}.");

                return self::FAILURE;
            }

            $this->newLine();
            $this->info("License written to {$outPath}.");
        }

        return self::SUCCESS;
    }
}
