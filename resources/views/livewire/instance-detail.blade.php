<div>
    @if ($open)
        <div style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:40;"
             x-transition.opacity wire:click="close"></div>

        <div style="position:fixed;top:0;right:0;height:100vh;width:min(560px,100vw);z-index:50;
                    background:var(--gray-900,#18181b);border-left:1px solid #27272a;overflow-y:auto;padding:1.5rem;"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0">

            {{-- Header --}}
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
                <div>
                    <div style="font-size:1.15rem;font-weight:700;">{{ $name }}</div>
                    <div style="opacity:.5;font-size:.8rem;">{{ data_get($detail, 'location', '—') }}</div>
                </div>
                <button wire:click="close" style="opacity:.6;font-size:1.5rem;line-height:1;cursor:pointer;background:none;border:none;color:inherit;">&times;</button>
            </div>

            {{-- Detail block --}}
            <div style="border:1px solid #27272a;border-radius:.6rem;padding:1rem;margin-bottom:1.25rem;font-size:.85rem;">
                @php
                    $rows = [
                        'Status'       => data_get($detail, 'status'),
                        'Type'         => data_get($detail, 'type') === 'virtual-machine' ? 'VM' : 'Container',
                        'Architecture' => data_get($detail, 'architecture'),
                        'Created'      => data_get($detail, 'created_at'),
                        'Memory limit' => data_get($detail, 'config.limits.memory', '—'),
                        'CPU limit'    => data_get($detail, 'config.limits.cpu', '—'),
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
                    <div style="display:flex;align-items:center;justify-content:space-between;border:1px solid #27272a;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.4rem;font-size:.82rem;">
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <span style="font-family:monospace;font-weight:600;">{{ $disk['name'] }}</span>
                            <span style="opacity:.5;font-family:monospace;">{{ $disk['path'] }}</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            @if ($disk['pool'])
                                <span style="opacity:.55;font-family:monospace;">{{ $disk['pool'] }}{{ $disk['size'] ? ' · '.$disk['size'] : '' }}</span>
                            @endif
                            @if ($disk['is_root'])
                                <span title="Reverts on snapshot restore" style="font-size:.68rem;padding:.1rem .45rem;border-radius:.3rem;background:rgba(239,68,68,.12);color:#ef4444;">in rollback</span>
                            @else
                                <span title="Survives snapshot restore" style="font-size:.68rem;padding:.1rem .45rem;border-radius:.3rem;background:rgba(34,197,94,.12);color:#22c55e;">persists</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Network --}}
            <div style="margin-bottom:1rem;">
                <div style="font-weight:600;font-size:.9rem;margin-bottom:.5rem;">Network</div>
                @foreach (data_get($config, 'nics', []) as $nic)
                    <div style="display:flex;align-items:center;justify-content:space-between;border:1px solid #27272a;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.4rem;font-size:.82rem;">
                        <span style="font-family:monospace;font-weight:600;">{{ $nic['name'] }}</span>
                        <span style="opacity:.6;font-family:monospace;">
                            {{ $nic['nictype'] }}@if($nic['parent']) → {{ $nic['parent'] }}@endif @if($nic['vlan']) · vlan {{ $nic['vlan'] }}@endif
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Profiles --}}
            <div style="margin-bottom:1.25rem;">
                <div style="font-weight:600;font-size:.9rem;margin-bottom:.5rem;">Profiles</div>
                <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                    @foreach (data_get($config, 'profiles', []) as $profile)
                        <span style="font-size:.75rem;padding:.15rem .5rem;border-radius:.35rem;background:rgba(99,102,241,.12);color:#818cf8;font-family:monospace;">{{ $profile }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Snapshots --}}
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
                <div style="font-weight:600;">Snapshots ({{ count($snapshots) }})</div>
                {{ $this->createSnapshotAction }}
            </div>

            @forelse ($snapshots as $snap)
                <div style="display:flex;align-items:center;justify-content:space-between;
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
