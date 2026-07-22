<?php

namespace App\Services\Licensing;

use App\Models\Cluster as ClusterModel;
use Illuminate\Support\Facades\Log;

/**
 * The ONE place the app asks "what is this install allowed to do?"
 *
 * Free is capped by SCALE, never CAPABILITY (monetization.md): every verb
 * works on up to `license.free_cluster_cap` endpoints; a valid, unexpired
 * license raises the endpoint cap. Nothing here ever disables a verb or
 * touches an EXISTING cluster row — the only question answered today is
 * "may a NEW cluster row be created?"
 *
 * The cap counts ROWS in `clusters` (endpoints), active or not — a
 * standalone box, a 1-node cluster, and an N-node cluster each count as
 * ONE, and toggling is_active does not free a slot (deleting a row does).
 *
 * Any problem with the license file (missing, unreadable, tampered,
 * expired) degrades silently to the free tier. Registered as a singleton;
 * the license file is read at most once per request.
 */
class Entitlements
{
    private ?License $license = null;

    private bool $resolved = false;

    public function __construct(
        private readonly LicenseVerifier $verifier,
        private readonly ?string $licensePath,
        private readonly int $freeClusterCap,
    ) {}

    /** The valid, unexpired license — or null (free tier). */
    public function license(): ?License
    {
        if (! $this->resolved) {
            $this->license = $this->resolve();
            $this->resolved = true;
        }

        return $this->license;
    }

    /** Endpoint rows this install may hold. */
    public function maxClusters(): int
    {
        return $this->license()?->maxClusters ?? $this->freeClusterCap;
    }

    /** Endpoint rows this install currently holds (active or not). */
    public function clusterCount(): int
    {
        return ClusterModel::query()->count();
    }

    /** The Step 6 gate: may a NEW cluster row be created? */
    public function canAddCluster(): bool
    {
        return $this->clusterCount() < $this->maxClusters();
    }

    /** 'Free', or the licensed tier capitalized ('Fleet'). For UI copy. */
    public function tierLabel(): string
    {
        $license = $this->license();

        return $license === null ? 'Free' : ucfirst($license->tier);
    }

    private function resolve(): ?License
    {
        $path = $this->licensePath;

        if (! $path || ! is_file($path)) {
            return null; // No license file — free tier, not an error.
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            Log::warning('kixctl license file exists but is unreadable; running free tier.', ['path' => $path]);

            return null;
        }

        try {
            $license = $this->verifier->verify($raw);
        } catch (InvalidLicenseException $e) {
            Log::warning('kixctl license failed verification; running free tier.', [
                'path' => $path,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        if ($license->isExpired()) {
            Log::warning('kixctl license is expired; running free tier for new clusters.', [
                'license_id' => $license->id,
                'expired_at' => $license->expiresAt?->format(DATE_ATOM),
            ]);

            return null;
        }

        return $license;
    }
}
