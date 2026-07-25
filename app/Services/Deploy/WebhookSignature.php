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
}
