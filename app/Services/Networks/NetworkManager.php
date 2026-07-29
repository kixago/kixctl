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
     * Register an existing, operator-owned network (br0, incusbr0, …) as an
     * UNMANAGED reference kixctl can target but never mutates. The bridge must
     * already exist on the cluster; we create ONLY the row — no createNetwork,
     * ever. This is the "use my existing infra" path.
     *
     * @param  array<string,mixed>  $attrs  key (existing bridge name), label, description, isolation, is_default
     */
    public function register(array $attrs): Network
    {
        $key = (string) ($attrs['key'] ?? '');
        $cluster = $this->cluster();

        if (! $cluster) {
            throw new RuntimeException('No active cluster to look up the network on.');
        }
        if (! $this->incus->networkExists($cluster, $key)) {
            throw new RuntimeException("No network named '{$key}' exists on the cluster to register.");
        }

        $makeDefault = (bool) ($attrs['is_default'] ?? false);

        $network = Network::create([
            'key' => $key,
            'label' => (string) ($attrs['label'] ?? $key),
            'managed' => false,   // a reference — kixctl never mutates it
            'ipv4_cidr' => null,  // not ours to state (the real bridge owns addressing)
            'ipv4_nat' => false,  // display placeholders; the operator's bridge owns these
            'ipv4_dhcp' => false,
            'isolation' => (string) ($attrs['isolation'] ?? 'open'),
            'is_locked' => false,
            'description' => $attrs['description'] ?? null,
            'sort' => (int) (Network::query()->max('sort') ?? 0) + 10,
        ]);

        if ($makeDefault) {
            $this->setDefault($network);
        }

        return $network;
    }

    /**
     * Update a managed network, classifying every change by what's safe:
     *   - metadata (label, description, sort, isolation) -> row only, instant.
     *   - is_default = true -> setDefault (preserves exactly-one; false is ignored,
     *     since a network becomes non-default by another becoming default).
     *   - ipv4_nat / ipv4_dhcp -> row + a live PATCH to the bridge (applies at once;
     *     Incus reconfigures dnsmasq, nothing to restart).
     *   - key -> refused: immutable post-create (renaming a bridge = delete + recreate).
     *   - ipv4_cidr -> only when the bridge has NO instances; otherwise refused,
     *     because changing the subnet under running containers strands them.
     *
     * @param  array<string,mixed>  $attrs
     */
    public function update(Network $network, array $attrs): Network
    {
        if (array_key_exists('key', $attrs) && $attrs['key'] !== $network->key) {
            throw new RuntimeException("A network's key can't be changed — delete '{$network->key}' and create a new one.");
        }
        unset($attrs['key'], $attrs['managed'], $attrs['is_locked']); // never edited here

        $makeDefault = (bool) ($attrs['is_default'] ?? false);
        unset($attrs['is_default']); // applied via setDefault to keep exactly-one

        // Unmanaged rows are pure REFERENCES — kixctl never mutates the operator's
        // bridge. Only a managed network syncs config/description to Incus.
        if (! $network->managed) {
            unset($attrs['ipv4_cidr'], $attrs['ipv4_nat'], $attrs['ipv4_dhcp']); // not ours to set
            $network->fill($attrs)->save();

            if ($makeDefault && ! $network->is_default) {
                $this->setDefault($network);
            }

            return $network->refresh();
        }

        $cluster = $this->cluster();
        $bridgeExists = $cluster && $this->incus->networkExists($cluster, $network->key);
        $usedBy = $bridgeExists ? (int) ($this->incus->network($cluster, $network->key)['used_by'] ?? 0) : 0;

        // CIDR change is safe only on an unused bridge — otherwise it strands leases.
        $newCidr = array_key_exists('ipv4_cidr', $attrs) ? (($attrs['ipv4_cidr'] ?: null)) : $network->ipv4_cidr;
        $cidrChanged = $newCidr !== $network->ipv4_cidr;
        if ($cidrChanged && $usedBy > 0) {
            throw new RuntimeException("Can't change the subnet of '{$network->key}' while {$usedBy} instance(s) are on it — move or delete them first.");
        }

        // Which real-bridge config keys need a live PATCH (merge, so untouched keys survive).
        $liveConfig = [];
        if (array_key_exists('ipv4_nat', $attrs) && (bool) $attrs['ipv4_nat'] !== (bool) $network->ipv4_nat) {
            $liveConfig['ipv4.nat'] = $attrs['ipv4_nat'] ? 'true' : 'false';
        }
        if (array_key_exists('ipv4_dhcp', $attrs) && (bool) $attrs['ipv4_dhcp'] !== (bool) $network->ipv4_dhcp) {
            $liveConfig['ipv4.dhcp'] = $attrs['ipv4_dhcp'] ? 'true' : 'false';
        }
        if ($cidrChanged) {
            $liveConfig['ipv4.address'] = $newCidr ?: 'auto';
        }

        // A label/description edit re-syncs the Incus description too.
        $descTouched = array_key_exists('label', $attrs) || array_key_exists('description', $attrs);

        // Row first (metadata + toggles + cidr intent), then the live bridge.
        $network->fill($attrs)->save();

        if ($bridgeExists && ($liveConfig !== [] || $descTouched)) {
            $this->incus->updateNetwork(
                $cluster,
                $network->key,
                $liveConfig,
                $descTouched ? $this->incusDescription($network) : null,
            );
        }

        if ($makeDefault && ! $network->is_default) {
            $this->setDefault($network);
        }

        return $network->refresh();
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

        // Only a MANAGED network owns its bridge — tear it down (refusing if it's
        // in use). An unmanaged row is just a REFERENCE: deleting it forgets the
        // reference and never touches the operator's real bridge (br0, br28, …).
        if ($network->managed) {
            $cluster = $this->cluster();

            if ($cluster && $this->incus->networkExists($cluster, $network->key)) {
                $info = $this->incus->network($cluster, $network->key);
                if ((int) ($info['used_by'] ?? 0) > 0) {
                    throw new RuntimeException("Network '{$network->key}' is in use by {$info['used_by']} instance(s) and cannot be deleted.");
                }
                $this->incus->deleteNetwork($cluster, $network->key);
            }
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
            $this->incusDescription($network),
        );
    }

    /**
     * The text kixctl writes to the Incus network description: the row's own
     * description, or its label as a friendly fallback so `incus network list`
     * always shows something meaningful rather than a blank.
     */
    private function incusDescription(Network $network): string
    {
        return (string) ($network->description ?: $network->label);
    }

    private function cluster(): ?Cluster
    {
        return collect($this->registry->all())->first();
    }
}
