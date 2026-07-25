<?php

namespace Tests\Unit;

use App\Services\Deploy\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests — WebhookSignature has zero framework dependencies, so these
 * run without booting Laravel. The expected header is computed the same way
 * Forgejo computes it: hash_hmac('sha256', <raw body>, <secret>) as lowercase hex.
 */
class WebhookSignatureTest extends TestCase
{
    private string $secret = 's3cr3t-webhook-key';

    private string $body = '{"ref":"refs/heads/main","after":"abc123"}';

    private function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    public function test_accepts_a_correct_signature(): void
    {
        $sig = $this->sign($this->body, $this->secret);

        $this->assertTrue(WebhookSignature::valid($this->body, $sig, $this->secret));
    }

    public function test_rejects_a_tampered_body(): void
    {
        $sig = $this->sign($this->body, $this->secret);
        $tampered = $this->body.' ';

        $this->assertFalse(WebhookSignature::valid($tampered, $sig, $this->secret));
    }

    public function test_rejects_a_wrong_secret(): void
    {
        $sig = $this->sign($this->body, 'a-different-secret');

        $this->assertFalse(WebhookSignature::valid($this->body, $sig, $this->secret));
    }

    public function test_rejects_a_missing_or_empty_signature(): void
    {
        $this->assertFalse(WebhookSignature::valid($this->body, null, $this->secret));
        $this->assertFalse(WebhookSignature::valid($this->body, '', $this->secret));
    }

    public function test_rejects_when_no_secret_is_configured(): void
    {
        $sig = $this->sign($this->body, '');

        $this->assertFalse(WebhookSignature::valid($this->body, $sig, ''));
    }
}
