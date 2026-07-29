<?php

namespace App\Services\Profiles;

use App\Models\Profile;
use App\Services\Incus\Cluster;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Create/register/update/delete/default kixctl profiles — the metadata row AND
 * the real Incus profile, kept in lockstep. The Profile MODEL owns the row
 * invariants (the locked `kix` guard lives in Profile::booted()); this SERVICE
 * owns the cluster side, and it is the exact sibling of NetworkManager:
 *
 *   - create()   -> row first, then the real Incus profile (root disk on an
 *                   auto-resolved pool); rolls the row back on failure.
 *   - register() -> an UNMANAGED reference to an existing profile (power,
 *                   default); creates ONLY the row, never createProfile.
 *   - update()   -> classified, live-safe edits. Metadata/description are safe
 *                   always; the root-disk POOL is refused while instances
 *                   inherit the profile (moving it strands them — the exact
 *                   parallel to a network CIDR change); device changes go
 *                   through a read-modify-write PUT so nothing else is stripped.
 *                   Unmanaged rows: metadata only, never touches Incus.
 *   - delete()   -> managed = delete the Incus profile (refused if in use);
 *                   unmanaged = forget the row, the operator's profile untouched.
 *
 * Every mutation here is gated behind $profile->managed exactly like the network
 * engine, and is proven in isolation by the kixctl:profile-*-probe commands
 * BEFORE any Filament UI drives it.
 */
class ProfileManager
{
    public function __construct(
        private ClusterRegistry $registry,
        private IncusClient $incus,
    ) {}

    /**
     * Create a managed profile: the row first, then the real Incus profile with
     * a root disk on an auto-resolved (or pinned) pool. If the cluster create
     * fails, the row is rolled back so we never strand a phantom row.
     *
     * Optional $attrs['nic_network'] attaches an eth0 NIC on that network key —
     * but the seeded `kix` is deliberately root-disk-only (placement is the
     * per-instance NIC), so this stays blank for the default.
     *
     * @param  array<string,mixed>  $attrs
     */
    public function create(array $attrs): Profile
    {
        $attrs['managed'] = true;    // this path only makes managed profiles
        $attrs['is_locked'] = false; // only the seeded default is locked
        $attrs['sort'] = $attrs['sort'] ?? ((int) (Profile::query()->max('sort') ?? 0) + 10);

        $makeDefault = (bool) ($attrs['is_default'] ?? false);
        unset($attrs['is_default']); // applied after create, as an invariant

        $nicNetwork = $attrs['nic_network'] ?? null;
        unset($attrs['nic_network']); // not a column; applied as a device

        $profile = Profile::create($attrs);

        try {
            $this->createProfileOnCluster($profile, is_string($nicNetwork) && $nicNetwork !== '' ? $nicNetwork : null);
        } catch (\Throwable $e) {
            $profile->delete(); // roll back the phantom row
            throw $e;
        }

        if ($makeDefault) {
            $this->setDefault($profile);
        }

        return $profile;
    }

    /**
     * Register an existing, operator-owned profile (power, default, …) as an
     * UNMANAGED reference kixctl can target but never mutates. The profile must
     * already exist on the cluster; we create ONLY the row — no createProfile,
     * no putProfile, ever. This is the "use my existing profile" path.
     *
     * @param  array<string,mixed>  $attrs  key (existing profile name), label, description, is_default
     */
    public function register(array $attrs): Profile
    {
        $key = (string) ($attrs['key'] ?? '');
        $cluster = $this->cluster();

        if (! $cluster) {
            throw new RuntimeException('No active cluster to look up the profile on.');
        }
        if (! $this->incus->profileExists($cluster, $key)) {
            throw new RuntimeException("No profile named '{$key}' exists on the cluster to register.");
        }

        $makeDefault = (bool) ($attrs['is_default'] ?? false);

        $profile = Profile::create([
            'key' => $key,
            'label' => (string) ($attrs['label'] ?? $key),
            'managed' => false,  // a reference — kixctl never mutates it
            'pool' => null,      // not ours to state (the real profile owns its devices)
            'is_locked' => false,
            'description' => $attrs['description'] ?? null,
            'sort' => (int) (Profile::query()->max('sort') ?? 0) + 10,
        ]);

        if ($makeDefault) {
            $this->setDefault($profile);
        }

        return $profile;
    }

    /**
     * Update a profile, classifying every change by what's safe:
     *   - metadata (label, description, sort) -> row, and a description re-sync
     *     to Incus for managed rows.
     *   - is_default = true -> setDefault (preserves exactly-one; false is
     *     ignored, since a profile becomes non-default by another becoming one).
     *   - pool (root-disk pool) -> row + a read-modify-write PUT of the root
     *     device, but ONLY while no instance inherits the profile; otherwise
     *     refused, because moving the root disk's pool under running containers
     *     strands them (the exact parallel to a network CIDR change).
     *   - nic_network -> attach/detach the eth0 NIC device via the same PUT
     *     (blank/'' detaches; a key attaches eth0 on that network).
     *   - key / managed / is_locked -> never edited here.
     *
     * For UNMANAGED rows, only the metadata row is touched — kixctl never mutates
     * the operator's real profile.
     *
     * @param  array<string,mixed>  $attrs
     */
    public function update(Profile $profile, array $attrs): Profile
    {
        if (array_key_exists('key', $attrs) && $attrs['key'] !== $profile->key) {
            throw new RuntimeException("A profile's key can't be changed — delete '{$profile->key}' and create a new one.");
        }
        unset($attrs['key'], $attrs['managed'], $attrs['is_locked']); // never edited here

        $makeDefault = (bool) ($attrs['is_default'] ?? false);
        unset($attrs['is_default']); // applied via setDefault to keep exactly-one

        $nicTouched = array_key_exists('nic_network', $attrs);
        $nicNetwork = $nicTouched ? (($attrs['nic_network'] ?: null)) : null;
        unset($attrs['nic_network']); // not a column

        // Unmanaged rows are pure REFERENCES — kixctl never mutates the
        // operator's profile. Only metadata on the row changes.
        if (! $profile->managed) {
            unset($attrs['pool']); // not ours to set
            $profile->fill($attrs)->save();

            if ($makeDefault && ! $profile->is_default) {
                $this->setDefault($profile);
            }

            return $profile->refresh();
        }

        $cluster = $this->cluster();
        $exists = $cluster && $this->incus->profileExists($cluster, $profile->key);

        // Guard a pool change against inheritors before we touch anything.
        $newPool = array_key_exists('pool', $attrs) ? (($attrs['pool'] ?: null)) : $profile->pool;
        $poolChanged = $newPool !== $profile->pool;

        $usedBy = 0;
        if ($exists) {
            $live = $this->incus->profile($cluster, $profile->key);
            $usedBy = is_countable($live['used_by'] ?? null) ? count($live['used_by']) : 0;
        }

        if ($poolChanged && $usedBy > 0) {
            throw new RuntimeException("Can't change the root-disk pool of '{$profile->key}' while {$usedBy} instance(s) inherit it — move or delete them first.");
        }

        $descTouched = array_key_exists('label', $attrs) || array_key_exists('description', $attrs);

        // Row first (metadata + pool intent), then the live profile.
        $profile->fill($attrs)->save();

        if ($exists && ($poolChanged || $nicTouched || $descTouched)) {
            // Read-modify-write: never blindly overwrite the whole profile — pull
            // the live config/devices, mutate only what changed, PUT it back. This
            // is what keeps a device edit from stripping anything else.
            $live = $this->incus->profile($cluster, $profile->key);
            $config = (array) ($live['config'] ?? []);
            $devices = (array) ($live['devices'] ?? []);

            if ($poolChanged) {
                $pool = $newPool ?: $this->resolvePool($cluster);
                $root = (array) ($devices['root'] ?? ['type' => 'disk', 'path' => '/']);
                $root['type'] = 'disk';
                $root['path'] = $root['path'] ?? '/';
                $root['pool'] = $pool;
                $devices['root'] = $root;
            }

            if ($nicTouched) {
                if ($nicNetwork === null) {
                    unset($devices['eth0']); // detach
                } else {
                    $devices['eth0'] = ['type' => 'nic', 'network' => $nicNetwork];
                }
            }

            $this->incus->putProfile(
                $cluster,
                $profile->key,
                $devices,
                $config,
                $descTouched ? $this->incusDescription($profile) : (string) ($live['description'] ?? ''),
            );
        }

        if ($makeDefault && ! $profile->is_default) {
            $this->setDefault($profile);
        }

        return $profile->refresh();
    }

    /**
     * Delete a managed profile: the Incus profile (if present and unused) then
     * the row. The model guard rejects the locked row before we touch the
     * cluster; a profile still inherited by instances is refused with a clear
     * message rather than a raw Incus error. An unmanaged row is just a
     * REFERENCE: deleting it forgets the reference, the operator's real profile
     * (power, default, …) is never touched.
     */
    public function delete(Profile $profile): void
    {
        if ($profile->is_locked) {
            // The model guard throws too; fail early with the same intent.
            throw new RuntimeException("'{$profile->key}' is the locked default and cannot be deleted.");
        }

        if ($profile->managed) {
            $cluster = $this->cluster();

            if ($cluster && $this->incus->profileExists($cluster, $profile->key)) {
                $live = $this->incus->profile($cluster, $profile->key);
                $usedBy = is_countable($live['used_by'] ?? null) ? count($live['used_by']) : 0;
                if ($usedBy > 0) {
                    throw new RuntimeException("Profile '{$profile->key}' is inherited by {$usedBy} instance(s) and cannot be deleted.");
                }
                $this->incus->deleteProfile($cluster, $profile->key);
            }
        }

        $wasDefault = $profile->is_default;
        $profile->delete();

        // If we removed the default, hand it back to the guaranteed fallback so
        // exactly-one-default holds and new instances still resolve a profile.
        if ($wasDefault) {
            $fallback = Profile::fallback();
            if ($fallback) {
                $this->setDefault($fallback);
            }
        }
    }

    /**
     * Reset the profile set to seed: remove kixctl-CREATED extras (non-locked
     * managed profiles) and re-assert the locked `kix` as the default. Never
     * touches the locked row or UNMANAGED references (power, default — the
     * operator's own, deliberately registered). A managed extra that's in use is
     * skipped (delete() refuses it), not force-removed.
     *
     * $dryRun returns the plan without changing anything.
     *
     * @return array{removed:list<string>, skipped:list<string>, kept_locked:string, kept_unmanaged:list<string>}
     */
    public function backToDefaults(bool $dryRun = false): array
    {
        $locked = Profile::ensureDefault(); // kix — always present

        $extras = Profile::query()
            ->where('is_locked', false)
            ->where('managed', true)
            ->get();

        $keptUnmanaged = Profile::query()->where('managed', false)->pluck('key')->all();

        $removed = [];
        $skipped = [];

        foreach ($extras as $profile) {
            if ($dryRun) {
                $removed[] = $profile->key;

                continue;
            }
            try {
                $this->delete($profile); // deletes the Incus profile; refuses if in use
                $removed[] = $profile->key;
            } catch (\Throwable $e) {
                $skipped[] = $profile->key.' ('.$e->getMessage().')';
            }
        }

        if (! $dryRun) {
            $this->setDefault($locked->refresh()); // exactly-one, back on kix
        }

        return [
            'removed' => $removed,
            'skipped' => $skipped,
            'kept_locked' => $locked->key,
            'kept_unmanaged' => $keptUnmanaged,
        ];
    }

    /**
     * Make $profile the sole is_default row. It may be a managed profile or an
     * unmanaged reference (you can point the default at your own profile). Pure
     * DB — no cluster mutation.
     */
    public function setDefault(Profile $profile): void
    {
        DB::transaction(function () use ($profile): void {
            Profile::query()
                ->where('is_default', true)
                ->where('id', '!=', $profile->id)
                ->update(['is_default' => false]);

            if (! $profile->is_default) {
                $profile->forceFill(['is_default' => true])->save();
            }
        });
    }

    /**
     * Create the real Incus profile for a managed row (idempotent): a root disk
     * on the row's pool (auto-resolved if null), plus an optional eth0 NIC. If
     * the profile already exists on the cluster it's adopted as-is (the coredns
     * provisioner may have created `kix` already) — never re-created or mutated.
     */
    private function createProfileOnCluster(Profile $profile, ?string $nicNetwork = null): void
    {
        $cluster = $this->cluster();
        if (! $cluster) {
            throw new RuntimeException('No active cluster to create the profile on.');
        }

        if ($this->incus->profileExists($cluster, $profile->key)) {
            return; // already there — treat as a no-op (adopt it)
        }

        $pool = $profile->pool ?: $this->resolvePool($cluster);

        $devices = [
            'root' => ['type' => 'disk', 'path' => '/', 'pool' => $pool],
        ];
        if ($nicNetwork !== null && $nicNetwork !== '') {
            $devices['eth0'] = ['type' => 'nic', 'network' => $nicNetwork];
        }

        $this->incus->createProfile(
            $cluster,
            $profile->key,
            devices: $devices,
            description: $this->incusDescription($profile),
        );
    }

    /**
     * Pick the storage pool for a profile's root disk WITHOUT asking the
     * operator: an explicit config pin wins; otherwise a single pool is used
     * as-is; a pool named `default` is preferred when there are several; else the
     * first by name. Auto-discovery from the cluster, not a borrowed setting —
     * the same heuristic the CoreDNS provisioner uses.
     */
    private function resolvePool(Cluster $cluster): string
    {
        $pinned = (string) config('networks.profile.pool', '');
        if ($pinned !== '') {
            return $pinned;
        }

        $pools = collect($this->incus->storagePools($cluster))
            ->pluck('name')
            ->filter()
            ->values();

        if ($pools->isEmpty()) {
            throw new RuntimeException(
                'No Incus storage pool found on the cluster; create one (e.g. `incus admin init`) before kixctl can provision, or set KIXCTL_POOL.'
            );
        }

        if ($pools->count() === 1) {
            return (string) $pools->first();
        }

        if ($pools->contains('default')) {
            return 'default';
        }

        return (string) $pools->sort()->values()->first();
    }

    /**
     * The text kixctl writes to the Incus profile description: the row's own
     * description, or its label as a friendly fallback so `incus profile list`
     * always shows something meaningful rather than a blank.
     */
    private function incusDescription(Profile $profile): string
    {
        return (string) ($profile->description ?: $profile->label);
    }

    private function cluster(): ?Cluster
    {
        return collect($this->registry->all())->first();
    }
}
