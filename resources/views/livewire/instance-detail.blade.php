<div>
    <style>
        [x-cloak] {
            display: none !important;
        }

        .kix-spin {
            width: 1.1rem;
            height: 1.1rem;
            border: 2px solid #3f3f46;
            border-top-color: #6366f1;
            border-radius: 50%;
            display: inline-block;
            animation: kixspin .7s linear infinite;
        }

        @keyframes kixspin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>

    @if ($open)
        <div style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:40;" x-transition.opacity wire:click="close">
        </div>

        <div style="position:fixed;top:0;right:0;height:100vh;width:min(560px,100vw);z-index:50;
                background:var(--gray-900,#18181b);border-left:1px solid #27272a;overflow-y:auto;padding:1.5rem;"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0">

            {{-- Header --}}
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
                <div>
                    <div style="font-size:1.15rem;font-weight:700;">{{ $name }}</div>
                    <div style="opacity:.5;font-size:.8rem;">{{ data_get($detail, 'location', '—') }}</div>
                </div>
                <div style="display:flex;align-items:center;gap:.75rem;">
                    {{ $this->deleteInstanceAction }}
                    <button wire:click="close"
                        style="opacity:.6;font-size:1.5rem;line-height:1;cursor:pointer;background:none;border:none;color:inherit;">&times;</button>
                </div>
            </div>

            {{-- Detail block --}}
            <div
                style="border:1px solid #27272a;border-radius:.6rem;padding:1rem;margin-bottom:1.25rem;font-size:.85rem;">
                @php
                    $iconf = data_get($detail, 'config', []);
                    $iexpanded = data_get($detail, 'expanded_config', []);

                    $memory = $iexpanded['limits.memory'] ?? ($iconf['limits.memory'] ?? null);
                    $cpu = $iexpanded['limits.cpu'] ?? ($iconf['limits.cpu'] ?? null);
                    $nesting = $iexpanded['security.nesting'] ?? ($iconf['security.nesting'] ?? null);

                    $rows = [
                        'Status' => data_get($detail, 'status'),
                        'Type' => data_get($detail, 'type') === 'virtual-machine' ? 'VM' : 'Container',
                        'Architecture' => data_get($detail, 'architecture'),
                        'Created' => data_get($detail, 'created_at'),
                        'Memory limit' => $memory ?: 'unlimited',
                        'CPU limit' => $cpu ?: 'unlimited',
                        'Nesting' => $nesting === 'true' ? 'enabled' : 'off',
                        'Description' => data_get($detail, 'description') ?: '—',
                    ];
                @endphp
                @foreach ($rows as $label => $value)
                    <div style="display:flex;justify-content:space-between;padding:.25rem 0;">
                        <span style="opacity:.55;">{{ $label }}</span>
                        <span style="font-family:monospace;">{{ $value ?: '—' }}</span>
                    </div>
                @endforeach
            </div>

            {{-- Storage: the rollback boundary made visible --}}
            <div style="margin-bottom:1rem;">
                <div style="font-weight:600;font-size:.9rem;margin-bottom:.5rem;">Storage</div>
                @foreach (data_get($config, 'disks', []) as $disk)
                    <div
                        style="display:flex;align-items:center;justify-content:space-between;border:1px solid #27272a;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.4rem;font-size:.82rem;">
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <span style="font-family:monospace;font-weight:600;">{{ $disk['name'] }}</span>
                            <span style="opacity:.5;font-family:monospace;">{{ $disk['path'] }}</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            @if ($disk['pool'])
                                <span
                                    style="opacity:.55;font-family:monospace;">{{ $disk['pool'] }}{{ $disk['size'] ? ' · ' . $disk['size'] : '' }}</span>
                            @endif
                            @if ($disk['is_root'])
                                <span title="Reverts on snapshot restore"
                                    style="font-size:.68rem;padding:.1rem .45rem;border-radius:.3rem;background:rgba(239,68,68,.12);color:#ef4444;">in
                                    rollback</span>
                            @else
                                <span title="Survives snapshot restore"
                                    style="font-size:.68rem;padding:.1rem .45rem;border-radius:.3rem;background:rgba(34,197,94,.12);color:#22c55e;">persists</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Network --}}
            <div style="margin-bottom:1rem;">
                <div style="font-weight:600;font-size:.9rem;margin-bottom:.5rem;">Network</div>
                @foreach (data_get($config, 'nics', []) as $nic)
                    <div
                        style="display:flex;align-items:center;justify-content:space-between;border:1px solid #27272a;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.4rem;font-size:.82rem;">
                        <span style="font-family:monospace;font-weight:600;">{{ $nic['name'] }}</span>
                        <span style="opacity:.6;font-family:monospace;">
                            {{ $nic['nictype'] }}@if ($nic['parent'])
                                → {{ $nic['parent'] }}
                                @endif @if ($nic['vlan'])
                                    · vlan {{ $nic['vlan'] }}
                                @endif
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Profiles --}}
            <div style="margin-bottom:1.25rem;">
                <div style="font-weight:600;font-size:.9rem;margin-bottom:.5rem;">Profiles</div>
                <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                    @foreach (data_get($config, 'profiles', []) as $profile)
                        <span
                            style="font-size:.75rem;padding:.15rem .5rem;border-radius:.35rem;background:rgba(99,102,241,.12);color:#818cf8;font-family:monospace;">{{ $profile }}</span>
                    @endforeach
                </div>
            </div>
            {{-- Logs (P2-A): tabbed — files + console --}}
            <div style="margin-bottom:1.25rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;">
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <button wire:click="showFiles"
                            style="font-size:.8rem;padding:.25rem .7rem;border-radius:.35rem;cursor:pointer;border:1px solid #27272a;
                     background:{{ $logView === 'files' ? 'rgba(99,102,241,.15)' : 'transparent' }};
                     color:{{ $logView === 'files' ? '#818cf8' : 'inherit' }};">
                            Log files
                        </button>
                        <button wire:click="showConsole"
                            style="font-size:.8rem;padding:.25rem .7rem;border-radius:.35rem;cursor:pointer;border:1px solid #27272a;
                     background:{{ $logView === 'console' ? 'rgba(99,102,241,.15)' : 'transparent' }};
                     color:{{ $logView === 'console' ? '#818cf8' : 'inherit' }};">
                            Console
                        </button>
                    </div>
                    <button wire:click="refreshLogs" wire:loading.attr="disabled" title="Reload"
                        style="opacity:.6;font-size:.78rem;padding:.25rem .6rem;border-radius:.35rem;cursor:pointer;background:none;border:1px solid #27272a;color:inherit;">
                        <span wire:loading.remove wire:target="refreshLogs">↻ Refresh</span>
                        <span wire:loading wire:target="refreshLogs">…</span>
                    </button>
                </div>

                {{-- Files tab --}}
                @if ($logView === 'files')
                    @if (empty($logFiles))
                        <div style="opacity:.5;font-size:.85rem;padding:.75rem 0;">No log files available for this
                            instance.</div>
                    @else
                        <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.6rem;">
                            @foreach ($logFiles as $file)
                                <button wire:click="viewLogFile('{{ $file['name'] }}')"
                                    style="font-size:.72rem;font-family:monospace;padding:.15rem .55rem;border-radius:.35rem;cursor:pointer;border:1px solid #27272a;
                         background:{{ $selectedLogFile === $file['name'] ? 'rgba(99,102,241,.12)' : 'transparent' }};
                         color:{{ $selectedLogFile === $file['name'] ? '#818cf8' : 'inherit' }};">
                                    {{ $file['name'] }}
                                </button>
                            @endforeach
                        </div>

                        <div wire:loading.class="opacity-50" wire:target="viewLogFile,refreshLogs"
                            style="border:1px solid #27272a;border-radius:.5rem;background:#0f0f11;max-height:340px;overflow:auto;">
                            @if (trim($logContent) === '')
                                <div style="opacity:.5;font-size:.8rem;padding:.85rem;">Empty.</div>
                            @else
                                <pre
                                    style="margin:0;padding:.85rem;font-family:monospace;font-size:.72rem;line-height:1.45;white-space:pre;color:#d4d4d8;">{{ $logContent }}</pre>
                            @endif
                        </div>
                    @endif
                @endif

                {{-- Console tab --}}
                @if ($logView === 'console')
                    <div wire:loading.class="opacity-50" wire:target="showConsole,refreshLogs"
                        style="border:1px solid #27272a;border-radius:.5rem;background:#0f0f11;max-height:340px;overflow:auto;">
                        @if (trim($consoleContent) === '')
                            <div style="opacity:.5;font-size:.8rem;padding:.85rem;">
                                No console output captured. Kernel/serial output appears here once the instance writes
                                to its console; VGA boot menus may show little after cleanup.
                            </div>
                        @else
                            <pre
                                style="margin:0;padding:.85rem;font-family:monospace;font-size:.72rem;line-height:1.45;white-space:pre-wrap;word-break:break-word;color:#d4d4d8;">{{ $consoleContent }}</pre>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Snapshots --}}
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
                <div style="font-weight:600;">Snapshots ({{ count($snapshots) }})</div>
                @if ($opToken === '')
                    {{ $this->createSnapshotAction }}
                @endif
            </div>

            {{-- Streaming snapshot-op island (P2-C tail): pure Alpine, fed only by broadcasts.
                 Spinner for create/delete (no progress bytes); determinate bar if a restore
                 emits fs_progress. wire:key forces a fresh mount + Echo subscription per op. --}}
            @if ($opToken !== '')
                <div wire:key="op-{{ $opToken }}" x-data="opProgress(@js($opToken), @js($opLabel))"
                    style="border:1px solid #27272a;border-radius:.6rem;padding:.85rem 1rem;margin-bottom:.85rem;background:rgba(255,255,255,.015);">

                    {{-- indeterminate spinner --}}
                    <div x-show="!terminal && !determinate" style="display:flex;align-items:center;gap:.75rem;">
                        <span class="kix-spin"></span>
                        <span style="opacity:.85;font-size:.9rem;" x-text="message"></span>
                    </div>

                    {{-- determinate bar (restore with fs_progress) --}}
                    <div x-show="determinate && !terminal">
                        <div
                            style="display:flex;justify-content:space-between;font-size:.8rem;opacity:.75;margin-bottom:.35rem;">
                            <span x-text="stage ? (stage + '…') : message"></span>
                            <span><span x-text="percent"></span>%<span x-show="rate" style="opacity:.6;"> (<span
                                        x-text="rate"></span>)</span></span>
                        </div>
                        <div style="height:.5rem;border-radius:9999px;background:#27272a;overflow:hidden;">
                            <div
                                :style="{
                                    height: '100%',
                                    background: '#6366f1',
                                    borderRadius: '9999px',
                                    transition: 'width .2s ease',
                                    width: (percent || 0) + '%'
                                }">
                            </div>
                        </div>
                    </div>

                    {{-- terminal success --}}
                    <div x-show="terminal && ok" style="display:flex;align-items:center;gap:.6rem;color:#22c55e;">
                        <span style="font-size:1.2rem;line-height:1;">✓</span>
                        <span style="font-size:.9rem;" x-text="message"></span>
                    </div>

                    {{-- terminal failure --}}
                    <div x-show="terminal && !ok">
                        <div style="display:flex;align-items:flex-start;gap:.6rem;color:#ef4444;">
                            <span style="font-size:1.2rem;line-height:1;">✕</span>
                            <span style="font-size:.85rem;" x-text="message"></span>
                        </div>
                        <div style="margin-top:.6rem;">
                            <button type="button" @click="$wire.dismissOp()"
                                style="padding:.4rem 1rem;border-radius:.5rem;border:1px solid #3f3f46;background:transparent;color:inherit;cursor:pointer;font-size:.82rem;">
                                Dismiss
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            @forelse ($snapshots as $snap)
                <div
                    style="display:flex;align-items:center;justify-content:space-between;
                    border:1px solid #27272a;border-radius:.5rem;padding:.6rem .85rem;margin-bottom:.5rem;">
                    <div>
                        <div style="font-weight:600;font-size:.9rem;">{{ $snap['name'] }}</div>
                        <div style="opacity:.5;font-size:.75rem;">{{ $snap['created_at'] }}</div>
                    </div>
                    <div style="display:flex;gap:.4rem;">
                        {{ ($this->restoreAction)(['snapshot' => $snap['name']]) }}
                        {{ ($this->deleteSnapshotAction)(['snapshot' => $snap['name']]) }}
                    </div>
                </div>
            @empty
                <div style="opacity:.5;font-size:.85rem;padding:1rem 0;">No snapshots yet.</div>
            @endforelse

            <x-filament-actions::modals />
        </div>
    @endif
</div>

<script>
    function opProgress(token, label) {
        return {
            token: token,
            phase: 'working',
            stage: null,
            percent: null,
            rate: null,
            message: label || 'Working…',
            determinate: false,
            terminal: false,
            ok: false,

            init() {
                if (!window.Echo) {
                    this.message = 'Live updates unavailable (Echo not loaded).';
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
                    this.message = e.message || 'Working…';
                } else if (e.phase === 'working') {
                    this.message = e.message || 'Working…';
                    if (this.determinate) this.percent = 100;
                } else if (e.phase === 'done') {
                    this.terminal = true;
                    this.ok = true;
                    this.percent = 100;
                    this.message = e.message || 'Done.';
                    // Let the ✓ land, then refresh the list + fan out and clear the island.
                    setTimeout(() => {
                        if (this.$wire) this.$wire.completeOp();
                    }, 900);
                } else if (e.phase === 'failed') {
                    this.terminal = true;
                    this.ok = false;
                    this.message = e.message || 'Operation failed.';
                }
            },
        };
    }
</script>
