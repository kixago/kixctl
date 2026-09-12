<?php

namespace App\Console\Commands;

use App\Services\Incus\IncusEnroller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proof harness for the bolt-on trust-token enrollment, run against a real Incus
 * endpoint before the wizard UI is wired. Mints a cert, enrolls it with the token,
 * then makes an authenticated call to confirm the cluster now trusts it.
 */
class EnrollIncusCommand extends Command
{
    protected $signature = 'incus:enroll-test {endpoint : e.g. https://192.168.2.8:8443} {token : trust token from `incus config trust add`}';

    protected $description = 'Test bolt-on trust-token enrollment against a remote Incus (dev proof only).';

    public function handle(IncusEnroller $enroller): int
    {
        $endpoint = $this->argument('endpoint');
        $token = $this->argument('token');

        $this->info("Minting a client certificate and enrolling at {$endpoint} …");

        try {
            $result = $enroller->enroll($endpoint, $token);
        } catch (Throwable $e) {
            $this->error('Enrollment failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Enrolled. Certificate fingerprint: '.$result['fingerprint']);
        $this->line('Verifying the enrolled certificate authenticates …');

        $certFile = tempnam(sys_get_temp_dir(), 'kixcrt');
        $keyFile = tempnam(sys_get_temp_dir(), 'kixkey');
        file_put_contents($certFile, $result['certificate']);
        file_put_contents($keyFile, $result['key']);

        try {
            $resp = Http::withOptions([
                'cert' => $certFile,
                'ssl_key' => $keyFile,
                'verify' => false,
            ])->timeout(15)->get(rtrim($endpoint, '/').'/1.0');
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }

        $auth = $resp->json('metadata.auth');

        if ($resp->successful() && $auth === 'trusted') {
            $this->newLine();
            $this->info('Success — the cluster trusts the enrolled certificate (auth: trusted).');
            $this->line('Server: '.($resp->json('metadata.environment.server_name') ?: '?'));
            $this->comment('Remove the test cert from your cluster with:');
            $this->comment('  incus config trust remove '.$result['fingerprint']);

            return self::SUCCESS;
        }

        $this->error('Certificate enrolled but did not authenticate (auth: '.var_export($auth, true).').');

        return self::FAILURE;
    }
}
