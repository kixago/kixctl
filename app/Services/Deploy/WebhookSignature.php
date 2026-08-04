<?php

namespace App\Services\Deploy;

/**
 * Verifies a Forgejo (Gitea-compatible) webhook signature.
 *
 * Forgejo signs the RAW request body with HMAC-SHA256 keyed by the webhook's
 * shared secret and sends the lowercase hex digest in the X-Forgejo-Signature
 * header (X-Gitea-Signature is the compatibility alias). Verified against the
 * current Forgejo docs: the check is exactly
 *   hash_hmac('sha256', <raw body>, <secret>) === <header value>.
 *
 * Pure and side-effect free so it unit-tests without booting Laravel — the same
 * shape as LicenseVerifier. The comparison is constant-time (hash_equals) so the
 * expected digest can't be recovered through response timing.
 */
final class WebhookSignature
{
    public static function valid(string $rawBody, ?string $headerSignature, string $secret): bool
    {
        if ($headerSignature === null || $headerSignature === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $headerSignature);
    }

    /**
     * GitHub variant: the same HMAC-SHA256 of the raw body, but sent prefixed in
     * the X-Hub-Signature-256 header as "sha256=<hex>". Verified against GitHub's
     * documented scheme; the prefix is stripped before the constant-time compare.
     */
    public static function validGithub(string $rawBody, ?string $headerSignature, string $secret): bool
    {
        if ($headerSignature === null || $secret === '' || ! str_starts_with($headerSignature, 'sha256=')) {
            return false;
        }

        $provided = substr($headerSignature, strlen('sha256='));
        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }
}
