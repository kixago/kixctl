<div>
    {{-- Ingress records: app -> host -> ip:port. Every save fires the async
         publish (below) so CoreDNS + the owned Caddy edge update with a live
         spinner + build console — the page never locks up. --}}
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:.35rem;">
        <h3 style="font-weight:600; font-size:1rem;">{{ __('ingress.records.heading') }}</h3>
    </div>
    <p style="opacity:.7; font-size:.85rem; margin-bottom:.75rem;">{{ __('ingress.records.intro') }}</p>

    {{ $this->table }}

    @php($npI18n = [
        'title' => __('ingress.records.publish_title'),
        'pending' => __('networks.toast.pending'),
        'ensuring-network' => __('networks.toast.ensuring'),
        'ensuring-profile' => __('networks.toast.profile'),
        'building' => __('networks.toast.building'),
        'importing' => __('networks.toast.importing'),
        'launching' => __('networks.toast.launching'),
        'starting' => __('networks.toast.starting'),
        'leasing' => __('networks.toast.leasing'),
        'serving' => __('networks.toast.serving'),
        'done' => __('ingress.records.publish_done'),
        'failed' => __('ingress.records.publish_failed'),
        'unavailable' => __('networks.toast.unavailable'),
    ])
    @php($consoleI18n = [
        'heading' => __('networks.console.heading'),
        'waiting' => __('networks.console.waiting'),
        'show' => __('networks.console.show'),
        'hide' => __('networks.console.hide'),
        'unavailable' => __('networks.console.unavailable'),
    ])

    {{-- Collapsible build console: tails the caddy/resolver build over console.<token>,
         the same rail proven in N2. Present whenever a publish token exists. --}}
    @if ($publishToken)
        <div
            wire:key="pub-console-{{ $publishToken }}"
            x-data="provisionConsole(@js($publishToken), @js($consoleI18n))"
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

    {{-- Live publish toast: quick corner confirmation on the phase rail. --}}
    @if ($publishing && $publishToken)
        <div
            wire:key="pub-toast-{{ $publishToken }}"
            x-data="networkProvision(@js($publishToken), @js($npI18n))"
            x-init="init()"
            style="position:fixed; right:1.25rem; bottom:1.25rem; z-index:50; max-width:24rem; padding:.9rem 1rem; border-radius:.6rem; background:rgba(17,24,39,.96); color:#e5e7eb; box-shadow:0 10px 30px rgba(0,0,0,.35); font-size:.85rem;"
        >
            <div style="display:flex; align-items:center; gap:.55rem; margin-bottom:.35rem;">
                <span
                    x-show="!terminal"
                    style="width:.7rem;height:.7rem;border-radius:9999px;border:2px solid #6b7280;border-top-color:#e5e7eb;display:inline-block;animation:npspin .8s linear infinite;"
                ></span>
                <span x-show="terminal" x-cloak :style="ok ? 'color:#22c55e' : 'color:#ef4444'">●</span>
                <strong x-text="i18n.title"></strong>
            </div>
            <div x-text="message" style="opacity:.9;"></div>
            <div x-show="terminal" x-cloak style="margin-top:.55rem; text-align:right;">
                <button type="button" @click="$wire.set('publishing', false)" style="opacity:.7; text-decoration:underline;">{{ __('ingress.records.dismiss') }}</button>
            </div>
        </div>
        <style>@keyframes npspin { to { transform: rotate(360deg); } } [x-cloak]{display:none!important;}</style>
    @endif
</div>
