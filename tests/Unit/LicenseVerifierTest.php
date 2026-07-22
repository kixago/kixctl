<?php

namespace Tests\Unit;

use App\Services\Licensing\InvalidLicenseException;
use App\Services\Licensing\LicenseVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests — LicenseVerifier has zero framework dependencies, so
 * these run without booting Laravel. Each test builds an ephemeral
 * Ed25519 keypair; nothing here touches the real keys.
 */
class LicenseVerifierTest extends TestCase
{
    private string $secret;

    private string $publicB64;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->publicB64 = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'v' => LicenseVerifier::VERSION,
            'id' => 'lic_test0001',
            'licensee' => 'Test Operator',
            'email' => 'op@example.com',
            'tier' => 'fleet',
            'max_clusters' => 5,
            'issued_at' => '2026-07-22T00:00:00+00:00',
            'expires_at' => '2027-07-22T00:00:00+00:00',
        ], $overrides);
    }

    public function test_a_signed_license_verifies_and_hydrates(): void
    {
        $raw = LicenseVerifier::sign($this->payload(), $this->secret);

        $license = (new LicenseVerifier($this->publicB64))->verify($raw);

        $this->assertSame('lic_test0001', $license->id);
        $this->assertSame('Test Operator', $license->licensee);
        $this->assertSame('fleet', $license->tier);
        $this->assertSame(5, $license->maxClusters);
        $this->assertFalse($license->isExpired(new \DateTimeImmutable('2027-01-01T00:00:00+00:00')));
        $this->assertTrue($license->isExpired(new \DateTimeImmutable('2027-08-01T00:00:00+00:00')));
    }

    public function test_whitespace_and_line_wrapping_are_tolerated(): void
    {
        $raw = LicenseVerifier::sign($this->payload(), $this->secret);
        $wrapped = "  ".chunk_split($raw, 40, "\n")."\n";

        $license = (new LicenseVerifier($this->publicB64))->verify($wrapped);

        $this->assertSame(5, $license->maxClusters);
    }

    public function test_a_perpetual_license_never_expires(): void
    {
        $raw = LicenseVerifier::sign($this->payload(['expires_at' => null]), $this->secret);

        $license = (new LicenseVerifier($this->publicB64))->verify($raw);

        $this->assertNull($license->expiresAt);
        $this->assertFalse($license->isExpired(new \DateTimeImmutable('2099-01-01T00:00:00+00:00')));
    }

    public function test_a_tampered_payload_fails_verification(): void
    {
        $raw = LicenseVerifier::sign($this->payload(), $this->secret);
        [$payloadB64, $sigB64] = explode('.', $raw);

        // The classic strip attempt: raise max_clusters after signing.
        $data = json_decode(base64_decode($payloadB64), true);
        $data['max_clusters'] = 9999;
        $forged = base64_encode(json_encode($data)).'.'.$sigB64;

        $this->expectException(InvalidLicenseException::class);
        (new LicenseVerifier($this->publicB64))->verify($forged);
    }

    public function test_a_license_signed_by_a_different_key_fails(): void
    {
        $otherPair = sodium_crypto_sign_keypair();
        $raw = LicenseVerifier::sign($this->payload(), sodium_crypto_sign_secretkey($otherPair));

        $this->expectException(InvalidLicenseException::class);
        (new LicenseVerifier($this->publicB64))->verify($raw);
    }

    public function test_garbage_input_fails_cleanly(): void
    {
        $this->expectException(InvalidLicenseException::class);
        (new LicenseVerifier($this->publicB64))->verify('not a license at all');
    }

    public function test_an_unsupported_version_is_rejected(): void
    {
        $raw = LicenseVerifier::sign($this->payload(['v' => 99]), $this->secret);

        $this->expectException(InvalidLicenseException::class);
        (new LicenseVerifier($this->publicB64))->verify($raw);
    }

    public function test_a_nonpositive_cluster_cap_is_rejected(): void
    {
        $raw = LicenseVerifier::sign($this->payload(['max_clusters' => 0]), $this->secret);

        $this->expectException(InvalidLicenseException::class);
        (new LicenseVerifier($this->publicB64))->verify($raw);
    }

    public function test_no_configured_public_key_trusts_nothing(): void
    {
        $raw = LicenseVerifier::sign($this->payload(), $this->secret);

        $this->expectException(InvalidLicenseException::class);
        (new LicenseVerifier(''))->verify($raw);
    }
}
