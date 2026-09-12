<?php

namespace App\Services\Incus;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Enrolls kixctl as a trusted client on a remote Incus endpoint using a one-time
 * trust token — the bolt-on path. Mints an RSA keypair and a self-signed client
 * certificate, presents it on the TLS connection while POSTing the token (Incus
 * trusts the presented cert), then verifies the cert actually authenticates and
 * captures the server's identity so the caller can confirm which cluster it
 * connected to. Returns the PEM keypair for the caller to persist on the Cluster
 * row. This is the REST equivalent of `incus remote add --token`; kixctl never
 * shells out to the incus CLI.
 */
class IncusEnroller
{
    /**
     * @return array{certificate: string, key: string, fingerprint: string, server_name: string}
     */
    public function enroll(string $endpoint, string $token, string $name = 'kixctl'): array
    {
        [$certPem, $keyPem] = $this->mintKeypair($name);

        $base = rtrim($endpoint, '/');
        $certFile = $this->tempPem($certPem);
        $keyFile = $this->tempPem($keyPem);

        try {
            $client = Http::withOptions([
                'cert' => $certFile,
                'ssl_key' => $keyFile,
                'verify' => false, // self-signed server cert, same posture as the rest of the transport
            ])->timeout(15);

            // Register the presented certificate, authorized by the token.
            $response = $client->acceptJson()->post($base.'/1.0/certificates', [
                'type' => 'client',
                'name' => $name,
                'trust_token' => $token,
            ]);

            if ($response->failed()) {
                throw new RuntimeException($response->json('error') ?: trim($response->body()) ?: 'unknown error');
            }

            // Confirm the cert now authenticates, and read the server name — a
            // success we can't verify isn't a success worth reporting.
            $whoami = $client->get($base.'/1.0');
            if (! $whoami->successful() || $whoami->json('metadata.auth') !== 'trusted') {
                throw new RuntimeException('the certificate was registered but the cluster did not accept it');
            }

            return [
                'certificate' => $certPem,
                'key' => $keyPem,
                'fingerprint' => $this->fingerprint($certPem),
                'server_name' => $whoami->json('metadata.environment.server_name') ?: 'the cluster',
            ];
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    }

    /** @return array{0: string, 1: string} [certPem, keyPem] */
    protected function mintKeypair(string $name): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a private key. Is the PHP openssl extension configured?');
        }

        $csr = openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [$certPem, $keyPem];
    }

    /** Write a PEM string to a private 0600 temp file for the TLS transport. */
    protected function tempPem(string $pem): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kixenr');
        file_put_contents($path, $pem);
        chmod($path, 0600);

        return $path;
    }

    /** Incus identifies a certificate by the SHA-256 of its DER encoding. */
    protected function fingerprint(string $certPem): string
    {
        $der = base64_decode(preg_replace(
            '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/',
            '',
            $certPem,
        ));

        return hash('sha256', $der);
    }
}
