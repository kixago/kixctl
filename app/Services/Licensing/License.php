<?php

namespace App\Services\Licensing;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A verified kixctl license — the immutable payload AFTER the Ed25519
 * signature has been checked. Nothing constructs this except
 * LicenseVerifier::verify() (and tests). Holding one of these means
 * "the vendor signed exactly these entitlements."
 *
 * Expiry is a fact about the license, not a decision — Entitlements
 * decides what an expired license means (fall back to the free cap for
 * NEW clusters; existing clusters are never touched — scale/time gating,
 * never capability).
 */
final readonly class License
{
    public function __construct(
        public string $id,            // e.g. "lic_9f2c..." — issued, unique
        public string $licensee,      // display name: person or org
        public string $tier,          // 'fleet' now; 'enterprise' later
        public int $maxClusters,      // endpoint rows allowed (>= 1)
        public DateTimeImmutable $issuedAt,
        public ?DateTimeImmutable $expiresAt, // null = perpetual
        public ?string $email = null,
    ) {}

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now > $this->expiresAt;
    }
}
