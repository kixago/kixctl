<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Services\Licensing\Entitlements;
use App\Services\Licensing\LicenseVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entitlements against a real (sqlite :memory:) clusters table: the free
 * cap counts ROWS regardless of is_active, a valid license raises the
 * cap, and expired/tampered licenses degrade to free.
 */
class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    private string $publicB64;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->publicB64 = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    private function makeCluster(string $key, bool $active = true): Cluster
    {
        return Cluster::create([
            'key' => $key,
            'label' => ucfirst($key),
            'driver' => 'https',
            'url' => "https://{$key}.example.test:8443",
            'client_cert' => 'cert',
            'client_key' => 'key',
            'verify' => false,
            'is_active' => $active,
        ]);
    }

    private function entitlements(?string $licenseRaw, int $freeCap = 2): Entitlements
    {
        $path = null;

        if ($licenseRaw !== null) {
            $path = tempnam(sys_get_temp_dir(), 'kixlic');
            file_put_contents($path, $licenseRaw);
        }

        return new Entitlements(
            verifier: new LicenseVerifier($this->publicB64),
            licensePath: $path,
            freeClusterCap: $freeCap,
        );
    }

    private function signedLicense(int $maxClusters, ?string $expiresAt = '2030-01-01T00:00:00+00:00'): string
    {
        return LicenseVerifier::sign([
            'v' => LicenseVerifier::VERSION,
            'id' => 'lic_feature01',
            'licensee' => 'Feature Test',
            'email' => null,
            'tier' => 'fleet',
            'max_clusters' => $maxClusters,
            'issued_at' => '2026-07-22T00:00:00+00:00',
            'expires_at' => $expiresAt,
        ], $this->secret);
    }

    public function test_free_tier_allows_up_to_the_cap(): void
    {
        $e = $this->entitlements(null);

        $this->assertTrue($e->canAddCluster());

        $this->makeCluster('one');
        $this->assertTrue($e->canAddCluster());

        $this->makeCluster('two');
        $this->assertFalse($e->canAddCluster());
        $this->assertSame('Free', $e->tierLabel());
        $this->assertSame(2, $e->maxClusters());
    }

    public function test_inactive_rows_still_count_toward_the_cap(): void
    {
        $this->makeCluster('one');
        $this->makeCluster('two', active: false);

        $this->assertFalse($this->entitlements(null)->canAddCluster());
    }

    public function test_a_valid_license_raises_the_cap(): void
    {
        $this->makeCluster('one');
        $this->makeCluster('two');

        $e = $this->entitlements($this->signedLicense(maxClusters: 5));

        $this->assertTrue($e->canAddCluster());
        $this->assertSame(5, $e->maxClusters());
        $this->assertSame('Fleet', $e->tierLabel());
    }

    public function test_an_expired_license_degrades_to_free_for_new_clusters(): void
    {
        $this->makeCluster('one');
        $this->makeCluster('two');

        $e = $this->entitlements($this->signedLicense(maxClusters: 5, expiresAt: '2026-01-01T00:00:00+00:00'));

        $this->assertNull($e->license());
        $this->assertFalse($e->canAddCluster());
        $this->assertSame('Free', $e->tierLabel());
    }

    public function test_a_tampered_license_degrades_to_free(): void
    {
        $this->makeCluster('one');
        $this->makeCluster('two');

        $raw = $this->signedLicense(maxClusters: 5);
        [$payloadB64, $sigB64] = explode('.', $raw);
        $data = json_decode(base64_decode($payloadB64), true);
        $data['max_clusters'] = 9999;

        $e = $this->entitlements(base64_encode(json_encode($data)).'.'.$sigB64);

        $this->assertNull($e->license());
        $this->assertFalse($e->canAddCluster());
    }

    public function test_a_missing_license_file_is_simply_free_tier(): void
    {
        $e = new Entitlements(
            verifier: new LicenseVerifier($this->publicB64),
            licensePath: '/nonexistent/kixctl.license',
            freeClusterCap: 2,
        );

        $this->assertNull($e->license());
        $this->assertSame(2, $e->maxClusters());
    }
}
