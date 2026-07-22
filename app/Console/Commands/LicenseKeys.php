<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generate the Ed25519 license keypair. VENDOR-SIDE tooling — shipping
 * this code is harmless (signing needs the secret key, not the code).
 *
 * The secret key is written to a 0600 file and never printed (tmux
 * scrollback is forever). The public key IS printed — it's public; it
 * goes in KIXCTL_LICENSE_PUBLIC_KEY now and gets embedded in
 * config/license.php at release.
 */
class LicenseKeys extends Command
{
    protected $signature = 'license:keys
        {--out=storage/license/secret.key : Where to write the base64 secret key (0600)}
        {--force : Overwrite an existing secret key file}';

    protected $description = 'Generate an Ed25519 license signing keypair (vendor-side)';

    public function handle(): int
    {
        $out = base_path($this->option('out'));

        if (is_file($out) && ! $this->option('force')) {
            $this->error("Refusing to overwrite existing secret key at {$out} (use --force).");

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);

        $dir = dirname($out);

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("Could not create directory {$dir}.");

            return self::FAILURE;
        }

        if (file_put_contents($out, base64_encode($secret).PHP_EOL) === false) {
            $this->error("Could not write secret key to {$out}.");

            return self::FAILURE;
        }

        chmod($out, 0600);

        $this->info("Secret key written to {$out} (0600). It is NEVER printed and must NEVER ship or be committed.");
        $this->newLine();
        $this->line('Public key (base64) — put this in KIXCTL_LICENSE_PUBLIC_KEY:');
        $this->line(base64_encode($public));

        return self::SUCCESS;
    }
}
