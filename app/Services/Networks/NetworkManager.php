<?php

namespace App\Services\Networks;

use App\Models\Network;
use App\Services\Incus\Cluster;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Create/delete/default managed networks — the metadata row AND the real Incus
 * bridge, kept in lockstep. The Network MODEL owns the row invariants (the
 * locked kixbr0 guard lives in Network::booted()); this SERVICE owns the cluster
 * side: it creates the bridge on create, deletes it on delete, and keeps exactly
 * one is_default row.
 *
 * The bridge is otherwise created lazily by CorednsProvisioner::ensureNetwork the
 * first time something rides a network. Creating it eagerly here means a network
 * the operator makes in the UI is REAL immediately (auto-subnet visible), not a
 * promissory row with no bridge behind it.
 */
class NetworkManager
{
    public function __construct(
        private ClusterRegistry $registry,
        private IncusClient $incus,
    ) {}

    /**
     * Create a managed network: the row first, then the Incus bridge. If the
     * bridge create fails, the row is rolled back so we never strand a phantom
     * row with no bridge.
     *
     * @param  array<string,mixed>  $attrs
     */
    public function create(array $attrs): Network
    {
        $attrs['managed'] = true;    // this path only makes managed networks
        $attrs['is_locked'] = false; // only the seeded default is locked
        $attrs['sort'] = $attrs['sort'] ?? ((int) (Network::query()->max('sort') ?? 0) + 10);

        $makeDefault = (bool) ($attrs['is_default'] ?? false);
        unset($attrs['is_default']); // applied after create, as an invariant

        $network = Network::create($attrs);

        try {
            $this->createBridge($network);
        } catch (\Throwable $e) {
            $network->delete(); // roll back the phantom row
            throw $e;
        }

        if ($makeDefault) {
            $this->setDefault($network);
        }

        return $network;
    }

    /**
     * Delete a managed network: the Incus bridge (if present) then the row. The
     * model guard rejects the locked row before we touch the cluster; a bridge
     * still in use by instances is refused with a clear message rather than a
     * raw Incus error.
     */
    public function delete(Network $network): void
    {
        if ($network->is_locked) {
            // The model guard throws too; fail early with the same intent.
            throw new RuntimeException("'{$network->key}' is the locked default and cannot be deleted.");
        }

        $cluster = $this->cluster();

        if ($cluster && $this->incus->networkExists($cluster, $network->key)) {
            $info = $this->incus->network($cluster, $network->key);
            if ((int) ($info['used_by'] ?? 0) > 0) {
                throw new RuntimeException("Network '{$network->key}' is in use by {$info['used_by']} instance(s) and cannot be deleted.");
            }
            $this->incus->deleteNetwork($cluster, $network->key);
        }

        $wasDefault = $network->is_default;
        $network->delete();

        // If we removed the default, hand it back to the guaranteed fallback so
        // exactly-one-default holds and new instances still resolve a network.
        if ($wasDefault) {
            $fallback = Network::fallback();
            if ($fallback) {
                $this->setDefault($fallback);
            }
        }
    }

    /**
     * Make $network the sole is_default row. It may be a managed network or an
     * unmanaged reference (you can point the default at your own bridge). Pure
     * DB — no cluster mutation.
     */
    public function setDefault(Network $network): void
    {
        DB::transaction(function () use ($network): void {
            Network::query()
                ->where('is_default', true)
                ->where('id', '!=', $network->id)
                ->update(['is_default' => false]);

            if (! $network->is_default) {
                $network->forceFill(['is_default' => true])->save();
            }
        });
    }

    /** Eagerly create the Incus bridge for a managed network row (idempotent). */
    private function createBridge(Network $network): void
    {
        $cluster = $this->cluster();
        if (! $cluster) {
            throw new RuntimeException('No active cluster to create the network on.');
        }

        if ($this->incus->networkExists($cluster, $network->key)) {
            return; // already there — treat as a no-op
        }

        $this->incus->createNetwork(
            $cluster,
            $network->key,
            'bridge',
            $network->incusConfig(),
            $network->description ?: null,
        );
    }

    private function cluster(): ?Cluster
    {
        return collect($this->registry->all())->first();
    }
}
