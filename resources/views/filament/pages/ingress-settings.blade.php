<x-filament-panels::page>
    {{-- Settings shell: tabbed chips. Network first (create-first resolver flow),
         Ingress (zone/records), Storage coming soon. Tab state is Livewire-driven. --}}
    <div style="display:flex; align-items:center; gap:.4rem; margin-bottom:1.25rem; border-bottom:1px solid rgba(255,255,255,.08); padding-bottom:.75rem;">
        @foreach (['network', 'profiles', 'ingress', 'records'] as $chip)
            <button
                type="button"
                wire:click="$set('tab', '{{ $chip }}')"
                @class(['fi-btn'])
                style="padding:.4rem .9rem; border-radius:.5rem; font-size:.85rem; font-weight:600;
                    {{ $tab === $chip
                        ? 'background:rgba(59,130,246,.18); color:#93c5fd;'
                        : 'background:transparent; opacity:.7;' }}"
            >{{ __('settings.tab.'.$chip) }}</button>
        @endforeach
        <span style="padding:.4rem .9rem; font-size:.85rem; opacity:.4; cursor:not-allowed;">
            {{ __('settings.tab.storage') }} · {{ __('settings.tab.soon') }}
        </span>
    </div>

    @php($consoleI18n = [
        'heading' => __('networks.console.heading'),
        'waiting' => __('networks.console.waiting'),
        'show' => __('networks.console.show'),
        'hide' => __('networks.console.hide'),
        'unavailable' => __('networks.console.unavailable'),
    ])

    {{-- ==================== NETWORK TAB ==================== --}}
    @if ($tab === 'network')
        <x-filament::section>
            <x-slot name="heading">{{ __('networks.resolver.heading') }}</x-slot>

            @if (($resolver['state'] ?? 'absent') === 'ready')
                {{-- READY: resolver up. Show status + Rebuild. (Networks CRUD + config
                     surface land here next.) --}}
                <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.75rem;">
                    <span style="width:.6rem;height:.6rem;border-radius:9999px;display:inline-block;background:#22c55e;"></span>
                    <span>{{ __('networks.resolver.ready') }}</span>
                </div>
                <dl style="font-size:.85rem; opacity:.85; margin-bottom:1rem;">
                    <div style="display:flex; gap:.5rem; padding:.15rem 0;">
                        <dt style="min-width:8rem; opacity:.6;">resolver</dt>
                        <dd style="font-family:ui-monospace,monospace;">{{ $resolver['instance'] ?? '' }}</dd>
                    </div>
                    <div style="display:flex; gap:.5rem; padding:.15rem 0;">
                        <dt style="min-width:8rem; opacity:.6;">address</dt>
                        <dd style="font-family:ui-monospace,monospace; color:#93c5fd;">{{ $resolver['ip'] ?? '' }}</dd>
                    </div>
                    <div style="display:flex; gap:.5rem; padding:.15rem 0;">
                        <dt style="min-width:8rem; opacity:.6;">network</dt>
                        <dd style="font-family:ui-monospace,monospace;">{{ $resolver['network'] ?? '' }}</dd>
                    </div>
                </dl>
                <div style="display:flex; gap:.75rem;">
                    {{ $this->rebuildResolverAction }}
                </div>
            @elseif (($resolver['state'] ?? 'absent') === 'provisioning' || $provisioning)
                {{-- PROVISIONING: the streamed build runs. Console below; no config surface. --}}
                <div style="display:flex; align-items:center; gap:.5rem;">
                    <span style="width:.7rem;height:.7rem;border-radius:9999px;border:2px solid #6b7280;border-top-color:#e5e7eb;display:inline-block;animation:npspin .8s linear infinite;"></span>
                    <span>{{ __('networks.resolver.provisioning') }}</span>
                </div>
                <div wire:poll.5s="refreshResolver" style="display:none;"></div>
            @else
                {{-- ABSENT: nothing but a status line and a single Create action. --}}
                <p style="opacity:.8; margin-bottom:1rem;">{{ __('networks.resolver.absent_help') }}</p>
                <div style="display:flex; align-items:center; gap:1rem;">
                    {{ $this->createResolverAction }}
                    <span style="opacity:.55; font-size:.85rem;">{{ __('networks.resolver.absent') }}</span>
                </div>
            @endif
        </x-filament::section>

        {{-- Collapsible build console: collapsed = last line; expanded = live tail.
             Subscribes to console.<token> (.line), the same rail proven by the probe. --}}
        @if ($provisionToken)
            <div
                wire:key="console-{{ $provisionToken }}"
                x-data="provisionConsole(@js($provisionToken), @js($consoleI18n))"
                x-init="init()"
                style="margin-top:1rem; border:1px solid rgba(255,255,255,.08); border-radius:.5rem; overflow:hidden;"
            >
                <button
                    type="button"
                    @click="open = !open"
                    style="width:100%; display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:.6rem .8rem; background:rgba(255,255,255,.03); text-align:left;"
                >
                    <span style="font-family:ui-monospace,monospace; font-size:.8rem; opacity:.85; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                          x-text="open ? i18n.heading : last"></span>
                    <span style="font-size:.75rem; opacity:.6; flex-shrink:0;" x-text="open ? i18n.hide : i18n.show"></span>
                </button>
                <div
                    x-show="open"
                    x-cloak
                    x-ref="tail"
                    style="min-height:8rem; max-height:20rem; overflow:auto; padding:.6rem .8rem; background:#0b0f1a; font-family:ui-monospace,monospace; font-size:.78rem; line-height:1.4;"
                >
                    <template x-for="l in lines" :key="l.seq">
                        <div :style="l.stream === 'err' ? 'color:#9ca3af;' : 'color:#93c5fd;'" x-text="l.line"></div>
                    </template>
                    <div x-show="lines.length === 0" style="opacity:.5;" x-text="i18n.waiting"></div>
                </div>
            </div>
        @endif

        {{-- Networks: managed rows + the locked kixbr0 (shown, guarded). Create /
             Delete / Set default route through NetworkManager. --}}
        <div style="margin-top:1.5rem;">
            <h3 style="font-weight:600; font-size:1rem; margin-bottom:.75rem;">{{ __('networks.table.heading') }}</h3>
            {{ $this->table }}
        </div>
    @endif

    {{-- ==================== PROFILES TAB ==================== --}}
    {{-- The second owned entity. Its own standalone Livewire table (a Page can
         only host one table() — that's the Networks one), so the proven Network
         tab is untouched and a bad idiom here is a single-file revert. --}}
    @if ($tab === 'profiles')
        @livewire('profiles-table')
    @endif

    {{-- ==================== INGRESS TAB ==================== --}}
    @if ($tab === 'ingress')
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
    @endif

    {{-- ==================== RECORDS TAB ==================== --}}
    {{-- CRUD over app_routes; each save fires the async publish (CoreDNS + the
         owned Caddy edge) with a live spinner + console. Standalone Livewire
         table so the proven Network/Profiles tabs are untouched. --}}
    @if ($tab === 'records')
        @livewire('ingress-records-table')
    @endif

    {{-- Live provisioning toast: quick corner confirmation on the phase rail. The
         console (above) is the durable tail; this is the glanceable status. --}}
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
    @endif

    <style>@keyframes npspin { to { transform: rotate(360deg); } } [x-cloak]{display:none!important;}</style>

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

        function provisionConsole(token, i18n) {
            return {
                token: token,
                i18n: i18n,
                lines: [],
                open: true,
                last: i18n.waiting,
                max: 300,
                init() {
                    if (!window.Echo) {
                        this.last = i18n.unavailable;
                        return;
                    }
                    window.Echo.channel('console.' + this.token)
                        .listen('.line', (e) => this.onLine(e));
                },
                onLine(e) {
                    this.lines.push({ seq: e.seq, stream: e.stream, line: e.line });
                    if (this.lines.length > this.max) {
                        this.lines.splice(0, this.lines.length - this.max);
                    }
                    this.last = e.line;
                    this.$nextTick(() => {
                        if (this.open && this.$refs.tail) {
                            this.$refs.tail.scrollTop = this.$refs.tail.scrollHeight;
                        }
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
