<?php

namespace App\Services\Licensing;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Offline license verification — the second fence from monetization.md.
 *
 * Format (one logical line; whitespace/newlines are ignored):
 *
 *     base64(json payload) . base64(ed25519 detached signature)
 *
 * The signature is over the EXACT payload bytes, verified locally against
 * an embedded Ed25519 public key (config/license.php). No network call,
 * ever — works air-gapped, tamper-evident without our private key.
 *
 * The algorithm is FIXED (Ed25519 via libsodium, bundled with PHP). There
 * is deliberately no header and no algorithm field, so there is no
 * alg-confusion surface (the classic JWT "alg: none" failure mode).
 *
 * sign() lives here too so the issue command and the tests exercise the
 * same byte path the verifier reads — one codec, no drift.
 */
final class LicenseVerifier
{
    public const int VERSION = 1;

    /** Raw 32-byte Ed25519 public key, or null when none is configured. */
    private ?string $publicKey;

    public function __construct(?string $publicKeyBase64)
    {
        $publicKeyBase64 = trim((string) $publicKeyBase64);

        if ($publicKeyBase64 === '') {
            // No key configured (fresh dev checkout): every license is
            // untrusted and verify() throws. The app runs free-tier.
            $this->publicKey = null;

            return;
        }

        $key = base64_decode($publicKeyBase64, strict: true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidLicenseException('License public key is not a valid base64 Ed25519 public key.');
        }

        $this->publicKey = $key;
    }

    /**
     * Verify a raw license string and return the trusted License.
     *
     * @throws InvalidLicenseException on ANY failure — malformed input,
     *         bad signature, wrong version, or invalid payload shape.
     *         Expiry is NOT checked here (see License::isExpired()).
     */
    public function verify(string $raw): License
    {
        if ($this->publicKey === null) {
            throw new InvalidLicenseException('No license public key is configured.');
        }

        // Tolerate line wrapping / trailing newline from editors and email.
        $raw = preg_replace('/\s+/', '', $raw) ?? '';

        $parts = explode('.', $raw);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidLicenseException('License is malformed (expected payload.signature).');
        }

        $payload = base64_decode($parts[0], strict: true);
        $signature = base64_decode($parts[1], strict: true);

        if ($payload === false || $signature === false) {
            throw new InvalidLicenseException('License is not valid base64.');
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidLicenseException('License signature has the wrong length.');
        }

        if (! sodium_crypto_sign_verify_detached($signature, $payload, $this->publicKey)) {
            throw new InvalidLicenseException('License signature is invalid.');
        }

        // Only NOW is the payload trusted input.
        $data = json_decode($payload, associative: true);

        if (! is_array($data)) {
            throw new InvalidLicenseException('License payload is not valid JSON.');
        }

        return $this->hydrate($data);
    }

    /**
     * Sign a payload into the on-disk license format. Vendor-side only —
     * requires the Ed25519 SECRET key (64 bytes), which never ships.
     * Used by the license:issue command and the test suite.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function sign(array $payload, string $secretKey): string
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidLicenseException('Signing key has the wrong length for an Ed25519 secret key.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new InvalidLicenseException('License payload could not be encoded as JSON.');
        }

        $signature = sodium_crypto_sign_detached($json, $secretKey);

        return base64_encode($json).'.'.base64_encode($signature);
    }

    /**
     * Strict shape validation of an already-authenticated payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function hydrate(array $data): License
    {
        if (($data['v'] ?? null) !== self::VERSION) {
            throw new InvalidLicenseException('Unsupported license version.');
        }

        foreach (['id', 'licensee', 'tier'] as $field) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                throw new InvalidLicenseException("License payload is missing '{$field}'.");
            }
        }

        $maxClusters = $data['max_clusters'] ?? null;

        if (! is_int($maxClusters) || $maxClusters < 1) {
            throw new InvalidLicenseException('License max_clusters must be a positive integer.');
        }

        return new License(
            id: $data['id'],
            licensee: $data['licensee'],
            tier: $data['tier'],
            maxClusters: $maxClusters,
            issuedAt: $this->parseDate($data['issued_at'] ?? null, 'issued_at') ?? throw new InvalidLicenseException("License payload is missing 'issued_at'."),
            expiresAt: $this->parseDate($data['expires_at'] ?? null, 'expires_at'),
            email: is_string($data['email'] ?? null) ? $data['email'] : null,
        );
    }

    private function parseDate(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidLicenseException("License {$field} must be an ISO-8601 string or null.");
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $_e) {
            throw new InvalidLicenseException("License {$field} is not a parseable date.");
        }
    }
}
