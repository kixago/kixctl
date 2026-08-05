<div>
    {{-- Updates: per-app deploy lifecycle, grouped by app. Read live from Incus;
         act with Cutover (promote) / Revert (swing back) / Reap (prune old). --}}
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:.35rem;">
        <h3 style="font-weight:600; font-size:1rem;">{{ __('updates.heading') }}</h3>
    </div>
    <p style="opacity:.7; font-size:.85rem; margin-bottom:1rem;">{{ __('updates.intro') }}</p>

    {{-- LAN-reachability signpost (D26). Shown only when kixctl's resolver has an
         address; hidden (never errored) when the cluster is unreachable. Surfaces
         the zone + CoreDNS address so a first deploy that "resolves to nothing"
         from the LAN reads as a next step, not a dead end. kixctl never writes to
         the operator's resolver — this only tells them where to point it. --}}
    @if ($hint)
        <div x-data="{ copied: '' }" style="margin-bottom:1.1rem; padding:.7rem .85rem; border-radius:.5rem; background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2);">
            <div style="font-weight:600; font-size:.82rem; margin-bottom:.3rem;">{{ __('updates.reachability.heading') }}</div>
            <p style="opacity:.8; font-size:.8rem; margin:0 0 .55rem;">{{ __('updates.reachability.body') }}</p>
            <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
                @foreach ([['label' => __('updates.reachability.zone'), 'value' => $hint['zone']], ['label' => __('updates.reachability.resolver'), 'value' => $hint['coredns_ip']]] as $pair)
                    <div style="display:flex; align-items:center; gap:.45rem; background:rgba(255,255,255,.05); padding:.3rem .55rem; border-radius:.4rem;">
                        <span style="opacity:.55; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em;">{{ $pair['label'] }}</span>
                        <span style="font-family:ui-monospace,monospace; font-size:.82rem; color:#93c5fd;">{{ $pair['value'] }}</span>
                        <button type="button"
                            @click="navigator.clipboard.writeText(@js($pair['value'])); copied=@js($pair['value']); setTimeout(() => copied='', 1200)"
                            style="opacity:.7; font-size:.7rem; text-decoration:underline; cursor:pointer;">
                            <span x-show="copied !== @js($pair['value'])">{{ __('updates.reachability.copy') }}</span>
                            <span x-show="copied === @js($pair['value'])" x-cloak style="color:#22c55e;">{{ __('updates.reachability.copied') }}</span>
                        </button>
                    </div>
                @endforeach
            </div>
            <div style="margin-top:.5rem; font-size:.78rem;">
                <a href="{{ $hint['docs_url'] }}" target="_blank" rel="noopener" style="color:#93c5fd; text-decoration:underline;">{{ __('updates.reachability.docs') }}</a>
            </div>
        </div>
    @endif

    {{-- Live deploy watcher. A push-triggered build (DeployFromPush) broadcasts on
         the public `deploys` channel; this tab did NOT initiate it, so it subscribes
         unconditionally and shows an in-flight spinner per revision, then a terminal
         line. On a terminal phase the watcher asks Livewire to re-pull apps() so the
         landed revision's "ready to promote" banner appears with no manual refresh —
         the exact gap where "nothing happened" reports come from. --}}
    @php($dwI18n = [
        'building' => __('updates.deploy.fallback.building'),
        'done' => __('updates.deploy.fallback.done'),
    ])
    <div x-data="deployWatch(@js($dwI18n))" x-init="init()" x-show="items.length > 0" x-cloak style="margin-bottom:1rem;">
        <template x-for="d in items" :key="d.instance">
            <div
                style="display:flex; align-items:center; gap:.6rem; margin-bottom:.5rem; padding:.6rem .75rem; border-radius:.5rem;"
                :style="d.phase === 'failed'
                    ? 'background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25);'
                    : (d.terminal
                        ? 'background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.25);'
                        : 'background:rgba(59,130,246,.1); border:1px solid rgba(59,130,246,.22);')"
            >
                <span
                    x-show="!d.terminal"
                    style="width:.7rem;height:.7rem;border-radius:9999px;border:2px solid #6b7280;border-top-color:#e5e7eb;display:inline-block;animation:dwspin .8s linear infinite;flex-shrink:0;"
                ></span>
                <span x-show="d.terminal" x-cloak :style="d.phase === 'failed' ? 'color:#ef4444' : 'color:#22c55e'">●</span>
                <span style="font-size:.85rem;" x-text="d.message"></span>
            </div>
        </template>
        <style>@keyframes dwspin { to { transform: rotate(360deg); } } [x-cloak]{display:none!important;}</style>
    </div>

    {{-- Pools ready to promote (P3-7). A pool with any member holding a landed,
         unpromoted revision surfaces here with one Update all that promotes the whole
         batch through the proven per-app cutover (RunPoolUpdate). Members flip live on
         the `deploys` rail as the job runs, so this section shrinks and disappears on
         its own. Apps not in a pool are untouched and still promote individually in
         their own cards below. --}}
    @if (! empty($pools))
        <div style="margin-bottom:1.3rem;">
            <h4 style="font-weight:600; font-size:.9rem; margin-bottom:.15rem;">{{ __('pools.updates.heading') }}</h4>
            <p style="opacity:.7; font-size:.82rem; margin-bottom:.7rem;">{{ __('pools.updates.intro') }}</p>

            @foreach ($pools as $pool)
                <div style="border:1px solid rgba(245,158,11,.28); border-radius:.6rem; padding:.85rem 1rem; margin-bottom:.75rem; background:rgba(245,158,11,.06);">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                        <div style="min-width:0;">
                            <div style="display:flex; align-items:center; gap:.5rem;">
                                <span style="background:rgba(255,255,255,.06); padding:.2rem .55rem; border-radius:.4rem; font-weight:600; font-size:.85rem;">{{ $pool['label'] }}</span>
                                <span style="font-size:.78rem; color:#fbbf24;">{{ trans_choice('pools.updates.ready_count', count($pool['ready']), ['count' => count($pool['ready'])]) }}</span>
                            </div>
                            <div style="display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.55rem;">
                                @foreach ($pool['ready'] as $member)
                                    <span style="display:inline-flex; align-items:center; gap:.35rem; background:rgba(255,255,255,.04); padding:.2rem .5rem; border-radius:.35rem; font-size:.78rem;">
                                        <span style="font-weight:600;">{{ $member['app'] }}</span>
                                        <span style="font-family:ui-monospace,monospace; color:#93c5fd;">{{ $member['sha'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                        <div style="flex-shrink:0;">
                            {{ ($this->updateAllAction)(['poolId' => $pool['id'], 'pool' => $pool['label'], 'count' => count($pool['ready'])]) }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if (empty($apps))
        <div style="border:1px dashed rgba(255,255,255,.12); border-radius:.6rem; padding:1.5rem; text-align:center;">
            <p style="font-weight:600; margin-bottom:.35rem;">{{ __('updates.empty.heading') }}</p>
            <p style="opacity:.7; font-size:.85rem;">{{ __('updates.empty.description') }}</p>
        </div>
    @endif

    @foreach ($apps as $app)
        @php($live = collect($app['revisions'])->firstWhere('live', true))
        @php($ready = $app['update_ready'] ? collect($app['revisions'])->firstWhere('instance', $app['update_ready']) : null)
        @php($history = collect($app['revisions'])->filter(fn ($r) => ! $r['live'] && $r['instance'] !== $app['update_ready'])->values())

        <div style="border:1px solid rgba(255,255,255,.08); border-radius:.6rem; padding:1rem 1.1rem; margin-bottom:1rem;">
            {{-- app header --}}
            <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:.85rem;">
                <div style="display:flex; align-items:center; gap:.6rem; min-width:0;">
                    <span style="background:rgba(255,255,255,.06); padding:.2rem .55rem; border-radius:.4rem; font-weight:600; font-size:.85rem;">{{ $app['app'] }}</span>
                    @if ($app['host'])
                        <span style="font-family:ui-monospace,monospace; font-size:.82rem; color:#93c5fd; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $app['host'] }}</span>
                    @endif
                </div>
                @if ($app['reap_eligible'])
                    <div style="flex-shrink:0;">{{ ($this->reapAction)(['app' => $app['app']]) }}</div>
                @endif
            </div>

            {{-- live revision --}}
            @if ($live)
                <div style="display:flex; align-items:center; gap:.6rem; padding:.5rem .65rem; border-radius:.45rem; background:rgba(34,197,94,.08);">
                    <span style="width:.6rem;height:.6rem;border-radius:9999px;background:#22c55e;flex-shrink:0;"></span>
                    <span style="font-weight:600; font-size:.85rem;">{{ __('updates.live') }}</span>
                    <span style="font-family:ui-monospace,monospace; font-size:.82rem;">{{ $live['sha'] }}</span>
                    <span style="opacity:.55; font-size:.8rem;">·</span>
                    <span style="opacity:.75; font-size:.8rem;">{{ $live['node'] ?? '—' }}</span>
                    @if ($live['ip'])
                        <span style="opacity:.55; font-size:.8rem;">·</span>
                        <span style="font-family:ui-monospace,monospace; font-size:.8rem; color:#93c5fd;">{{ $live['ip'] }}</span>
                    @endif
                    @unless ($live['running'])
                        <span style="margin-left:auto; font-size:.75rem; color:#f59e0b;">{{ __('updates.not_running') }}</span>
                    @endunless
                </div>
            @endif

            {{-- update-ready banner --}}
            @if ($ready)
                <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:.6rem; padding:.6rem .75rem; border-radius:.45rem; background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.25);">
                    <div style="min-width:0;">
                        <div style="font-weight:600; font-size:.85rem; color:#fbbf24;">{{ __('updates.ready.heading') }}</div>
                        <div style="opacity:.85; font-size:.8rem; margin-top:.1rem;">
                            <span style="font-family:ui-monospace,monospace;">{{ $ready['sha'] }}</span>
                            @if ($ready['created_at'])
                                · {{ \Illuminate\Support\Carbon::parse($ready['created_at'])->diffForHumans() }}
                            @endif
                        </div>
                    </div>
                    <div style="flex-shrink:0;">{{ ($this->cutoverAction)(['app' => $app['app'], 'instance' => $ready['instance']]) }}</div>
                </div>
            @endif

            {{-- previous revisions --}}
            @if ($history->isNotEmpty())
                <div style="margin-top:.85rem;">
                    <h4 style="font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; opacity:.5; margin-bottom:.5rem;">{{ __('updates.history') }}</h4>
                    @foreach ($history as $rev)
                        <div style="display:flex; align-items:center; gap:.6rem; padding:.4rem .1rem;">
                            <span style="width:.5rem;height:.5rem;border-radius:9999px;background:{{ $rev['reap_eligible'] ? '#ef4444' : '#6b7280' }};flex-shrink:0;"></span>
                            <span style="font-family:ui-monospace,monospace; font-size:.82rem;">{{ $rev['sha'] }}</span>
                            @if ($rev['created_at'])
                                <span style="opacity:.5; font-size:.78rem;">{{ \Illuminate\Support\Carbon::parse($rev['created_at'])->diffForHumans() }}</span>
                            @endif
                            <span style="font-size:.72rem; padding:.1rem .45rem; border-radius:.3rem; background:rgba(255,255,255,.05); opacity:.8;">
                                @if ($rev['reap_eligible'])
                                    {{ __('updates.state.reap_eligible') }}
                                @elseif ($rev['retired_at'])
                                    {{ __('updates.state.retired') }}
                                @else
                                    {{ __('updates.state.inactive') }}
                                @endif
                            </span>
                            <div style="margin-left:auto;">{{ ($this->revertAction)(['app' => $app['app'], 'instance' => $rev['instance']]) }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach

    {{-- Action modals (confirmations) — required once for a Livewire component
         that renders its own Filament actions. --}}
    <x-filament-actions::modals />

    {{-- Live cutover/revert toast, on the same Reverb rail as the provisioners.
         Reuses the networkProvision() Alpine helper defined in the Settings shell
         (this tab renders inside that page), keyed by our own action token. --}}
    @php($npI18n = [
        'title' => __('updates.toast.title'),
        'pending' => __('updates.toast.pending'),
        'done' => __('updates.toast.done'),
        'failed' => __('updates.toast.failed'),
        'unavailable' => __('networks.toast.unavailable'),
    ])

    @if ($running && $actionToken)
        <div
            wire:key="updates-toast-{{ $actionToken }}"
            x-data="networkProvision(@js($actionToken), @js($npI18n))"
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
            <template x-if="ip">
                <div style="margin-top:.3rem; font-family:ui-monospace,monospace; color:#93c5fd;" x-text="ip"></div>
            </template>
            <div x-show="terminal" x-cloak style="margin-top:.55rem; text-align:right;">
                <button type="button" @click="$wire.set('running', false)" style="opacity:.7; text-decoration:underline;">{{ __('updates.toast.dismiss') }}</button>
            </div>
        </div>
        <style>@keyframes npspin { to { transform: rotate(360deg); } } [x-cloak]{display:none!important;}</style>
    @endif
</div>
