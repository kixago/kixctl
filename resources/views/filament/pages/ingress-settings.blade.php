<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="display:flex; gap:.75rem; margin-top:1.25rem;">
            {{ $this->saveAction }}
            {{ $this->defaultsAction }}
        </div>
    </form>

    <x-filament::section>
        <x-slot name="heading">{{ __('ingress.status.heading') }}</x-slot>

        <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.5rem;">
            <span style="width:.6rem;height:.6rem;border-radius:9999px;display:inline-block;background:{{ ($status['ready'] ?? false) ? '#22c55e' : '#f59e0b' }};"></span>
            <span>{{ $status['summary'] ?? '' }}</span>
        </div>

        @if (! empty($status['detail']))
            <dl style="font-size:.85rem; opacity:.85;">
                @foreach ($status['detail'] as $k => $v)
                    <div style="display:flex; gap:.5rem; padding:.15rem 0;">
                        <dt style="min-width:11rem; opacity:.6;">{{ $k }}</dt>
                        <dd style="font-family:ui-monospace,monospace;">{{ $v }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        <div wire:poll.15s="refreshStatus" style="display:none;"></div>
    </x-filament::section>

    {{-- Live provisioning toast: streams creating → building → launching → leasing → serving over Reverb. --}}
    @php($npI18n = [
        'title' => __('networks.toast.title'),
        'pending' => __('networks.toast.pending'),
        'ensuring-network' => __('networks.toast.ensuring'),
        'ensuring-profile' => __('networks.toast.profile'),
        'building' => __('networks.toast.building'),
        'importing' => __('networks.toast.importing'),
        'launching' => __('networks.toast.launching'),
        'starting' => __('networks.toast.starting'),
        'leasing' => __('networks.toast.leasing'),
        'serving' => __('networks.toast.serving'),
        'done' => __('networks.toast.done'),
        'failed' => __('networks.toast.failed'),
        'unavailable' => __('networks.toast.unavailable'),
    ])

    @if ($provisioning && $provisionToken)
        <div
            wire:key="netprov-{{ $provisionToken }}"
            x-data="networkProvision(@js($provisionToken), @js($npI18n))"
            x-init="init()"
            style="position:fixed; right:1.25rem; bottom:1.25rem; z-index:50; max-width:24rem; padding:.9rem 1rem; border-radius:.6rem; background:rgba(17,24,39,.96); color:#e5e7eb; box-shadow:0 10px 30px rgba(0,0,0,.35); font-size:.85rem;"
        >
            <div style="display:flex; align-items:center; gap:.55rem; margin-bottom:.35rem;">
                <span
                    x-show="!terminal"
                    style="width:.7rem;height:.7rem;border-radius:9999px;border:2px solid #6b7280;border-top-color:#e5e7eb;display:inline-block;animation:npspin .8s linear infinite;"
                ></span>
                <span x-show="terminal" x-cloak :style="ok ? 'color:#22c55e' : 'color:#ef4444'" x-text="ok ? '●' : '●'"></span>
                <strong x-text="i18n.title"></strong>
            </div>
            <div x-text="message" style="opacity:.9;"></div>
            <template x-if="ip">
                <div style="margin-top:.3rem; font-family:ui-monospace,monospace; color:#93c5fd;" x-text="(network ? network + ' · ' : '') + ip"></div>
            </template>
            <div x-show="terminal" x-cloak style="margin-top:.55rem; text-align:right;">
                <button type="button" @click="$wire.set('provisioning', false)" style="opacity:.7; text-decoration:underline;">dismiss</button>
            </div>
        </div>
        <style>@keyframes npspin { to { transform: rotate(360deg); } } [x-cloak]{display:none!important;}</style>
    @endif

    <script>
        function networkProvision(token, i18n) {
            return {
                token: token,
                i18n: i18n,
                phase: 'pending',
                message: i18n.pending,
                ip: null,
                network: null,
                terminal: false,
                ok: false,
                init() {
                    if (!window.Echo) {
                        this.message = i18n.unavailable;
                        return;
                    }
                    window.Echo.channel('network-provision.' + this.token)
                        .listen('.progress', (e) => this.onProgress(e));
                },
                onProgress(e) {
                    this.phase = e.phase;
                    if (e.network) this.network = e.network;
                    if (e.ip) this.ip = e.ip;

                    // Prefer the job's real message; fall back to a per-phase label.
                    this.message = e.message || i18n[e.phase] || this.message;

                    if (e.phase === 'done') {
                        this.terminal = true;
                        this.ok = true;
                        if (window.Livewire) window.Livewire.dispatch('network-provisioned');
                    } else if (e.phase === 'failed') {
                        this.terminal = true;
                        this.ok = false;
                        if (window.Livewire) window.Livewire.dispatch('network-provisioned');
                    }
                },
            };
        }
    </script>
</x-filament-panels::page>
