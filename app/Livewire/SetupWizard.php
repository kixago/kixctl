<?php

namespace App\Livewire;

use App\Models\Cluster;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Services\Incus\IncusEnroller;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * The pre-auth first-run wizard, mounted at the bare root — the app's only
 * full-page, non-Filament Livewire component. Three steps: the domain the panel
 * and apps live at, a real admin account (replacing the seeded default), and
 * which Incus kixctl drives.
 *
 * The Incus step offers two paths that share one screen: "start fresh" (drive
 * this appliance's own Incus over the local socket) and "bolt on" (enroll against
 * an existing cluster with a one-time trust token — the IncusEnroller mints a
 * keypair, registers it, and verifies it authenticates). finish() reports the
 * outcome as a toast and, on success, flips configured_at — which, with the
 * EnsureConfigured middleware, is what opens /admin.
 */
#[Layout('components.layouts.wizard')]
class SetupWizard extends Component
{
    public int $step = 1;

    public int $lastStep = 3;

    /** Step 1 — the domain the panel and apps are reached at. */
    public string $domain = '';

    /** Step 2 — the real admin account (replaces the seeded admin@kixctl.local). */
    public string $adminEmail = '';

    public string $adminPassword = '';

    public string $adminPasswordConfirmation = '';

    /** Step 3 — 'fresh' (local socket) or 'bolt' (enroll against an existing cluster). */
    public string $incusMode = 'fresh';

    /** Bolt-on: the Incus project the minted cert is restricted to (never unrestricted). */
    public string $boltProject = 'default';

    /** Bolt-on: HTTPS endpoint, pre-filled from the token's advertised addresses. */
    public string $boltEndpoint = '';

    /** Bolt-on: the one-time trust token from `incus config trust add`. */
    public string $boltToken = '';

    public function mount(): void
    {
        if (InstanceSetting::current()->isConfigured()) {
            $this->redirect('/admin');

            return;
        }

        $this->adminEmail = User::query()->orderBy('id')->value('email') ?? '';
    }

    /**
     * The token is a base64 JSON blob carrying the cluster's advertised addresses —
     * decode it to pre-fill the endpoint. Only fills an empty endpoint, so a manual
     * edit is never clobbered.
     */
    public function updatedBoltToken(string $value): void
    {
        $json = base64_decode(trim($value), true);
        if ($json === false) {
            return;
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded) && ! empty($decoded['addresses'][0]) && $this->boltEndpoint === '') {
            $this->boltEndpoint = 'https://'.$decoded['addresses'][0];
        }
    }

    /** The exact command the operator runs on their cluster — rebuilt live from the project. */
    public function getTrustCommandProperty(): string
    {
        $project = trim($this->boltProject) !== '' ? trim($this->boltProject) : 'default';

        return "incus config trust add kixctl --restricted --projects {$project}";
    }

    protected function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'domain' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i'],
            ],
            2 => [
                'adminEmail' => ['required', 'email', 'max:255'],
                'adminPassword' => ['required', 'string', 'min:12'],
                'adminPasswordConfirmation' => ['required', 'same:adminPassword'],
            ],
            3 => $this->incusMode === 'bolt' ? [
                'boltProject' => ['required', 'string', 'max:63'],
                'boltEndpoint' => ['required', 'url', 'starts_with:https://'],
                'boltToken' => ['required', 'string', 'min:20'],
            ] : [],
            default => [],
        };
    }

    public function next(): void
    {
        $this->validate($this->rulesForStep($this->step));
        $this->step = min($this->step + 1, $this->lastStep);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function finish()
    {
        $this->validate($this->rulesForStep(1) + $this->rulesForStep(2) + $this->rulesForStep(3));

        // Resolve the Incus connection first — bolt-on enrollment is the only step
        // that can fail against an external system. On failure we surface it (inline
        // + red toast) and stop, before touching the admin account or marking the
        // box configured, so a fresh appliance is never left half-set-up.
        if ($this->incusMode === 'bolt') {
            try {
                $enrolled = app(IncusEnroller::class)->enroll(trim($this->boltEndpoint), trim($this->boltToken));
            } catch (Throwable $e) {
                $message = $this->humanizeEnrollError($e->getMessage());
                $this->addError('boltToken', $message);
                $this->dispatch('notify', type: 'error', message: $message);

                return;
            }

            $cluster = [
                'key' => 'remote',
                'label' => 'Existing cluster',
                'driver' => 'https',
                'url' => rtrim(trim($this->boltEndpoint), '/'),
                'socket' => null,
                'client_cert' => $enrolled['certificate'],
                'client_key' => $enrolled['key'],
                'verify' => false,
            ];
            $success = "Connected to {$enrolled['server_name']} — certificate trusted.";
        } else {
            $cluster = [
                'key' => 'local',
                'label' => 'Local Incus',
                'driver' => 'socket',
                'url' => null,
                'socket' => config('incus.socket', '/var/lib/incus/unix.socket'),
                'client_cert' => null,
                'client_key' => null,
                'verify' => false,
            ];
            $success = 'kixctl is set up — welcome.';
        }

        $admin = User::query()->orderBy('id')->firstOrFail();
        $admin->update([
            'email' => $this->adminEmail,
            'password' => $this->adminPassword, // hashed by the model's cast
        ]);

        Cluster::query()->update(['is_active' => false]);
        Cluster::query()->updateOrCreate(['key' => $cluster['key']], $cluster + ['is_active' => true, 'sort' => 0]);

        InstanceSetting::current()->update([
            'domain' => $this->domain,
            'hostname' => strtok($this->domain, '.') ?: null,
            'configured_at' => now(),
        ]);

        Auth::login($admin);

        // The toast listener shows the green message, then navigates to the panel —
        // so success is acknowledged rather than a silent redirect.
        $this->dispatch('notify', type: 'success', message: $success, redirect: '/admin');
    }

    /** Turn raw transport/Incus errors into something a human can act on. */
    protected function humanizeEnrollError(string $raw): string
    {
        $r = strtolower($raw);

        return match (true) {
            str_contains($r, 'curl error 7'), str_contains($r, 'curl error 28'),
            str_contains($r, 'failed to connect'), str_contains($r, 'connection refused'),
            str_contains($r, 'timed out'), str_contains($r, 'could not resolve')
                => 'Could not reach the cluster at that address. Check the address and that Incus is listening for HTTPS there.',
            str_contains($r, 'no matching'), str_contains($r, 'not found'), str_contains($r, 'token')
                => 'That token was not accepted — it may be expired or already used. Generate a fresh one on your cluster and paste it in.',
            str_contains($r, 'did not accept'), str_contains($r, 'not trusted')
                => 'The cluster registered the certificate but did not accept it. Check the project you entered matches the token.',
            default => 'Could not connect to the cluster: '.$raw,
        };
    }

    public function render()
    {
        return view('livewire.setup-wizard');
    }
}
