<div>
    <style>
        [x-cloak] { display: none !important; }
        .kix-spin {
            width: 1.1rem; height: 1.1rem; border: 2px solid #3f3f46;
            border-top-color: #6366f1; border-radius: 50%; display: inline-block;
            animation: kixspin .7s linear infinite;
        }
        @keyframes kixspin { to { transform: rotate(360deg); } }
    </style>

    <div x-data="{ show: $wire.entangle('open') }" x-cloak>
        <div @click="show = false"
            :style="{ position: 'fixed', inset: '0', background: 'rgba(0,0,0,.5)', zIndex: 40, transition: 'opacity .3s ease', opacity: show ? '1' : '0', pointerEvents: show ? 'auto' : 'none' }">
        </div>

        <div
            :style="{ position: 'fixed', top: '0', right: '0', height: '100vh', width: 'min(560px,100vw)', zIndex: 50, background: 'var(--gray-900,#18181b)', borderLeft: '1px solid #27272a', overflowY: 'auto', padding: '1.5rem', willChange: 'transform', boxShadow: show ? '-8px 0 24px rgba(0,0,0,.3)' : 'none', transition: show ? 'transform .3s ease-out' : 'transform .25s ease-in', transform: show ? 'translateX(0)' : 'translateX(100%)' }">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
                <div>
                    <div style="font-size:1.15rem;font-weight:700;">{{ $name }}</div>
                    <div style="opacity:.5;font-size:.8rem;">{{ data_get($detail, 'location', '—') }}</div>
                </div>
                <div style="display:flex;align-items:center;gap:.75rem;">
                    {{ $this->renameInstanceAction }}
                    {{ $this->deleteInstanceAction }}
                    <button @click="show = false"
                        style="opacity:.6;font-size:1.5rem;line-height:1;cursor:pointer;background:none;border:none;color:inherit;">&times;</button>
                </div>
            </div>

            <div style="border:1px solid #27272a;border-radius:.6rem;padding:1rem;margin-bottom:1.25rem;font-size:.85rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                    <div style="font-weight:600;font-size:.9rem;">{{ __('instances.detail.config.heading') }}</div>
                    {{ $this->editConfigAction }}
                </div>

                @php
                    $iconf = data_get($detail, 'config', []);
                    $iexpanded = data_get($detail, 'expanded_config', []);

                    $memory = $iexpanded['limits.memory'] ?? ($iconf['limits.memory'] ?? null);
                    $cpu = $iexpanded['limits.cpu'] ?? ($iconf['limits.cpu'] ?? null);
                    $nesting = $iexpanded['security.nesting'] ?? ($iconf['security.nesting'] ?? null);

                    $rows = [
                        __('common.labels.status') => data_get($detail, 'status'),
                        __('common.labels.type') => data_get($detail, 'type') === 'virtual-machine' ? __('instances.types.vm') : __('instances.types.container'),
                        __('common.labels.architecture') => data_get($detail, 'architecture'),
                        __('common.labels.created') => data_get($detail, 'created_at'),
                        __('instances.create.memory_limit_label') => $memory ?: __('common.labels.unlimited'),
                        __('instances.create.cpu_limit_label') => $cpu ?: __('common.labels.unlimited'),
                        __('instances.create.security_nesting_label') => $nesting === 'true' ? __('common.labels.yes') : __('common.labels.no'),
                        __('common.labels.description') => data_get($detail, 'description') ?: '—',
                    ];
                @endphp
                @foreach ($rows as $label => $value)
                    <div style="display:flex;justify-content:space-between;padding:.25rem 0;">
                        <span style="opacity:.55;">{{ $label }}</span>
                        <span style="font-family:monospace;">{{ $value ?: '—' }}</span>
                    </div>
                @endforeach
            </div>

            <div style="margin-bottom:1rem;">
                <div style="font-weight:600;font-size:.9rem;margin-bottom:.5rem;">{{ __('instances.detail.storage.heading') }}</div>
                @foreach (data_get($config, 'disks', []) as $disk)
                    <div style="display:flex;align-items:center;justify-content:space-between;border:1px solid #27272a;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.4rem;font-size:.82rem;">
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <span style="font-family:monospace;font-weight:600;">{{ $disk['name'] }}</span>
                            <span style="opacity:.5;font-family:monospace;">{{ $disk['path'] }}</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            @if ($disk['pool'])
                                <span style="opacity:.55;font-family:monospace;">{{ $disk['pool'] }}{{ $disk['size'] ? ' · ' . $disk['size'] : '' }}</span>
                            @endif
                            @if ($disk['is_root'])
                                <span title="{{ __('instances.detail.storage.in_rollback_tooltip') }}"
                                    style="font-size:.68rem;padding:.1rem .45rem;border-radius:.3rem;background:rgba(239,68,68,.12);color:#ef4444;">{{ __('instances.detail.storage.in_rollback') }}</span>
                            @else
                                <span title="{{ __('instances.detail.storage.persists_tooltip') }}"
                                    style="font-size:.68rem;padding:.1rem .45rem;border-radius:.3rem;background:rgba(34,197,94,.12);color:#22c55e;">{{ __('instances.detail.storage.persists') }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div style="margin-bottom:1rem;">
                <div style="font-weight:600;font-size:.9rem;margin-bottom:.5rem;">{{ __('instances.detail.network.heading') }}</div>
                @foreach (data_get($config, 'nics', []) as $nic)
                    <div style="display:flex;align-items:center;justify-content:space-between;border:1px solid #27272a;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.4rem;font-size:.82rem;">
                        <span style="font-family:monospace;font-weight:600;">{{ $nic['name'] }}</span>
                        <span style="opacity:.6;font-family:monospace;">
                            {{ $nic['nictype'] }}@if ($nic['parent']) → {{ $nic['parent'] }} @endif
                            @if ($nic['vlan']) · {{ __('instances.detail.network.vlan', ['vlan' => $nic['vlan']]) }} @endif
                        </span>
                    </div>
                @endforeach
            </div>

            <div style="margin-bottom:1.25rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                    <div style="font-weight:600;font-size:.9rem;">{{ __('instances.detail.profiles.heading') }}</div>
                    {{ $this->editProfilesAction }}
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                    @foreach (data_get($config, 'profiles', []) as $profile)
                        <span style="font-size:.75rem;padding:.15rem .5rem;border-radius:.35rem;background:rgba(99,102,241,.12);color:#818cf8;font-family:monospace;">{{ $profile }}</span>
                    @endforeach
                </div>
            </div>

            <div style="margin-bottom:1.25rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;">
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <button wire:click="showFiles"
                            style="font-size:.8rem;padding:.25rem .7rem;border-radius:.35rem;cursor:pointer;border:1px solid #27272a; background:{{ $logView === 'files' ? 'rgba(99,102,241,.15)' : 'transparent' }}; color:{{ $logView === 'files' ? '#818cf8' : 'inherit' }};">
                            {{ __('instances.detail.tabs.files') }}
                        </button>
                        <button wire:click="showConsole"
                            style="font-size:.8rem;padding:.25rem .7rem;border-radius:.35rem;cursor:pointer;border:1px solid #27272a; background:{{ $logView === 'console' ? 'rgba(99,102,241,.15)' : 'transparent' }}; color:{{ $logView === 'console' ? '#818cf8' : 'inherit' }};">
                            {{ __('instances.detail.tabs.console') }}
                        </button>
                    </div>
                    <button wire:click="refreshLogs" wire:loading.attr="disabled" title="{{ __('common.actions.refresh') }}"
                        style="opacity:.6;font-size:.78rem;padding:.25rem .6rem;border-radius:.35rem;cursor:pointer;background:none;border:1px solid #27272a;color:inherit;">
                        <span wire:loading.remove wire:target="refreshLogs">{{ __('common.actions.refresh') }}</span>
                        <span wire:loading wire:target="refreshLogs">{{ __('common.actions.refreshing') }}</span>
                    </button>
                </div>

                @if ($logView === 'files')
                    @if (empty($logFiles))
                        <div style="opacity:.5;font-size:.85rem;padding:.75rem 0;">{{ __('instances.detail.logs.empty_files') }}</div>
                    @else
                        <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.6rem;">
                            @foreach ($logFiles as $file)
                                <button wire:click="viewLogFile('{{ $file['name'] }}')"
                                    style="font-size:.72rem;font-family:monospace;padding:.15rem .55rem;border-radius:.35rem;cursor:pointer;border:1px solid #27272a; background:{{ $selectedLogFile === $file['name'] ? 'rgba(99,102,241,.12)' : 'transparent' }}; color:{{ $selectedLogFile === $file['name'] ? '#818cf8' : 'inherit' }};">
                                    {{ $file['name'] }}
                                </button>
                            @endforeach
                        </div>

                        <div wire:loading.class="opacity-50" wire:target="viewLogFile,refreshLogs"
                            style="border:1px solid #27272a;border-radius:.5rem;background:#0f0f11;max-height:340px;overflow:auto;">
                            @if (trim($logContent) === '')
                                <div style="opacity:.5;font-size:.8rem;padding:.85rem;">{{ __('instances.detail.logs.empty_file_content') }}</div>
                            @else
                                <pre style="margin:0;padding:.85rem;font-family:monospace;font-size:.72rem;line-height:1.45;white-space:pre;color:#d4d4d8;">{{ $logContent }}</pre>
                            @endif
                        </div>
                    @endif
                @endif

                @if ($logView === 'console')
                    <div wire:loading.class="opacity-50" wire:target="showConsole,refreshLogs"
                        style="border:1px solid #27272a;border-radius:.5rem;background:#0f0f11;max-height:340px;overflow:auto;">
                        @if (trim($consoleContent) === '')
                            <div style="opacity:.5;font-size:.8rem;padding:.85rem;">
                                {{ __('instances.detail.logs.empty_console') }}
                            </div>
                        @else
                            <pre style="margin:0;padding:.85rem;font-family:monospace;font-size:.72rem;line-height:1.45;white-space:pre-wrap;word-break:break-word;color:#d4d4d8;">{{ $consoleContent }}</pre>
                        @endif
                    </div>
                @endif
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
                <div style="font-weight:600;">{{ __('instances.detail.snapshots.heading', ['count' => count($snapshots)]) }}</div>
                @if ($opToken === '')
                    {{ $this->createSnapshotAction }}
                @endif
            </div>

            @if ($opToken !== '')
                <div wire:key="op-{{ $opToken }}" x-data="opProgress(@js($opToken), @js($opLabel), @js(__('instances.op_progress')))"
                    style="border:1px solid #27272a;border-radius:.6rem;padding:.85rem 1rem;margin-bottom:.85rem;background:rgba(255,255,255,.015);">

                    <div x-show="!terminal && !determinate" style="display:flex;align-items:center;gap:.75rem;">
                        <span class="kix-spin"></span>
                        <span style="opacity:.85;font-size:.9rem;" x-text="message"></span>
                    </div>

                    <div x-show="determinate && !terminal">
                        <div style="display:flex;justify-content:space-between;font-size:.8rem;opacity:.75;margin-bottom:.35rem;">
                            <span x-text="stage ? (stage + '…') : message"></span>
                            <span><span x-text="percent"></span>%<span x-show="rate" style="opacity:.6;"> (<span x-text="rate"></span>)</span></span>
                        </div>
                        <div style="height:.5rem;border-radius:9999px;background:#27272a;overflow:hidden;">
                            <div :style="{ height: '100%', background: '#6366f1', borderRadius: '9999px', transition: 'width .2s ease', width: (percent || 0) + '%' }"></div>
                        </div>
                    </div>

                    <div x-show="terminal && ok" style="display:flex;align-items:center;gap:.6rem;color:#22c55e;">
                        <span style="font-size:1.2rem;line-height:1;">✓</span>
                        <span style="font-size:.9rem;" x-text="message"></span>
                    </div>

                    <div x-show="terminal && !ok">
                        <div style="display:flex;align-items:flex-start;gap:.6rem;color:#ef4444;">
                            <span style="font-size:1.2rem;line-height:1;">✕</span>
                            <span style="font-size:.85rem;" x-text="message"></span>
                        </div>
                        <div style="margin-top:.6rem;">
                            <button type="button" @click="$wire.dismissOp()"
                                style="padding:.4rem 1rem;border-radius:.5rem;border:1px solid #3f3f46;background:transparent;color:inherit;cursor:pointer;font-size:.82rem;">
                                {{ __('common.actions.dismiss') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            @forelse ($snapshots as $snap)
                <div style="display:flex;align-items:center;justify-content:space-between; border:1px solid #27272a;border-radius:.5rem;padding:.6rem .85rem;margin-bottom:.5rem;">
                    <div>
                        <div style="font-weight:600;font-size:.9rem;">{{ $snap['name'] }}</div>
                        <div style="opacity:.5;font-size:.75rem;">{{ $snap['created_at'] }}</div>
                    </div>
                    <div style="display:flex;gap:.4rem;{{ $opToken !== '' ? 'opacity:.4;pointer-events:none;' : '' }}">
                        {{ ($this->restoreAction)(['snapshot' => $snap['name']]) }}
                        {{ ($this->deleteSnapshotAction)(['snapshot' => $snap['name']]) }}
                    </div>
                </div>
            @empty
                <div style="opacity:.5;font-size:.85rem;padding:1rem 0;">{{ __('instances.detail.snapshots.empty') }}</div>
            @endforelse

            <x-filament-actions::modals />
        </div>
    </div>

    <script>
        function opProgress(token, label, i18n) {
            return {
                token: token,
                i18n: i18n,
                phase: 'working',
                stage: null,
                percent: null,
                rate: null,
                message: label || i18n.working,
                determinate: false,
                terminal: false,
                ok: false,
                init() {
                    if (!window.Echo) {
                        this.message = i18n.unavailable;
                        return;
                    }
                    window.Echo.channel('instance-op.' + this.token)
                        .listen('.progress', (e) => this.onProgress(e));
                },
                onProgress(e) {
                    this.phase = e.phase;
                    if (e.phase === 'downloading' && e.percent !== null && e.percent !== undefined) {
                        this.determinate = true;
                        this.percent = e.percent;
                        this.stage = e.stage;
                        this.rate = e.rate;
                        this.message = e.message || i18n.working;
                    } else if (e.phase === 'working') {
                        this.message = e.message || i18n.working;
                        if (this.determinate) this.percent = 100;
                    } else if (e.phase === 'done') {
                        this.terminal = true;
                        this.ok = true;
                        this.percent = 100;
                        this.message = e.message || i18n.done;
                        setTimeout(() => { if (this.$wire) this.$wire.completeOp(); }, 900);
                    } else if (e.phase === 'failed') {
                        this.terminal = true;
                        this.ok = false;
                        this.message = e.message || i18n.failed;
                    }
                },
            };
        }
    </script>
</div>
