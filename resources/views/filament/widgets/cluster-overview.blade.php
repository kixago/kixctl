<x-filament-widgets::widget>
    <div class="kx-dash">
        <style>
            .kx-dash{
                --kx-fg:#0f172a;--kx-muted:#64748b;--kx-surface:#fff;--kx-ring:rgba(15,23,42,.08);
                --kx-soft:#f1f5f9;--kx-run:#10b981;--kx-stop:#94a3b8;--kx-warn:#f59e0b;
                --kx-off:#f43f5e;--kx-accent:#6366f1;
                color:var(--kx-fg);font-variant-numeric:tabular-nums;
            }
            :is(.dark) .kx-dash{
                --kx-fg:#e2e8f0;--kx-muted:#94a3b8;--kx-surface:#1e293b;--kx-ring:rgba(255,255,255,.09);
                --kx-soft:#334155;--kx-stop:#64748b;
            }
            .kx-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;}
            .kx-title{display:flex;align-items:center;gap:.6rem;font-size:1.05rem;font-weight:650;letter-spacing:-.01em;}
            .kx-live{width:8px;height:8px;border-radius:99px;background:var(--kx-run);position:relative;flex:none;}
            .kx-live::after{content:"";position:absolute;inset:0;border-radius:99px;background:var(--kx-run);animation:kxpulse 2s ease-out infinite;}
            @keyframes kxpulse{0%{transform:scale(1);opacity:.6}100%{transform:scale(3.2);opacity:0}}
            .kx-meta{font-size:.72rem;color:var(--kx-muted);font-weight:500;}
            .kx-err{margin-bottom:1rem;padding:.7rem .9rem;border-radius:.6rem;font-size:.8rem;
            .kx-node-res{margin-top:.85rem;padding-top:.85rem;border-top:1px solid var(--kx-ring);display:flex;flex-direction:column;gap:.7rem;}
            .kx-res-row{}
            .kx-res-top{display:flex;justify-content:space-between;font-size:.68rem;color:var(--kx-muted);margin-bottom:.2rem;font-weight:600;}
            .kx-res-top b{color:var(--kx-fg);font-weight:640;}
            .kx-res-bar{height:6px;border-radius:99px;background:var(--kx-soft);overflow:hidden;}
            .kx-res-bar>i{display:block;height:100%;border-radius:99px;transition:width .4s ease;}
            .kx-load{display:flex;align-items:baseline;gap:.5rem;margin-top:.15rem;}
            .kx-load-label{font-size:.68rem;color:var(--kx-muted);font-weight:600;}
            .kx-load-val{font-size:.9rem;font-weight:680;letter-spacing:-.01em;}
            .kx-load-rest{font-size:.7rem;color:var(--kx-muted);}
                background:color-mix(in srgb,var(--kx-off) 12%,transparent);color:var(--kx-off);border:1px solid color-mix(in srgb,var(--kx-off) 30%,transparent);}

            .kx-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1rem;}
            @media(max-width:820px){.kx-stats{grid-template-columns:repeat(2,1fr);}}
            .kx-stat{background:var(--kx-surface);border:1px solid var(--kx-ring);border-radius:.8rem;
                padding:.95rem 1.1rem;position:relative;overflow:hidden;}
            .kx-stat::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent,var(--kx-accent));opacity:.9;}
            .kx-stat-label{font-size:.66rem;text-transform:uppercase;letter-spacing:.07em;font-weight:650;color:var(--kx-muted);}
            .kx-stat-num{font-size:2rem;font-weight:680;letter-spacing:-.02em;line-height:1.1;margin-top:.15rem;}
            .kx-stat-sub{font-size:.72rem;color:var(--kx-muted);margin-top:.15rem;font-weight:500;}

            .kx-fleet{background:var(--kx-surface);border:1px solid var(--kx-ring);border-radius:.8rem;padding:1rem 1.1rem;margin-bottom:1rem;}
            .kx-fleet-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;}
            .kx-fleet-top span{font-size:.66rem;text-transform:uppercase;letter-spacing:.07em;font-weight:650;color:var(--kx-muted);}
            .kx-bar{height:9px;border-radius:99px;background:var(--kx-soft);overflow:hidden;display:flex;}
            .kx-bar>i{display:block;height:100%;}
            .kx-legend{display:flex;gap:1.2rem;margin-top:.7rem;flex-wrap:wrap;}
            .kx-legend div{display:flex;align-items:center;gap:.4rem;font-size:.75rem;color:var(--kx-muted);}
            .kx-dot{width:8px;height:8px;border-radius:2px;flex:none;}

            .kx-section-label{font-size:.66rem;text-transform:uppercase;letter-spacing:.07em;font-weight:650;color:var(--kx-muted);margin:0 .2rem .6rem;}
            .kx-nodes{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:.75rem;}
            .kx-node{background:var(--kx-surface);border:1px solid var(--kx-ring);border-radius:.8rem;padding:1rem 1.1rem;transition:transform .12s ease,box-shadow .12s ease;}
            .kx-node:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(15,23,42,.06);}
            .kx-node-head{display:flex;align-items:center;gap:.5rem;margin-bottom:.15rem;}
            .kx-node-dot{width:9px;height:9px;border-radius:99px;flex:none;}
            .kx-node-name{font-weight:640;font-size:.95rem;letter-spacing:-.01em;}
            .kx-node-count{margin-left:auto;font-size:.72rem;color:var(--kx-muted);font-weight:600;}
            .kx-roles{display:flex;gap:.3rem;flex-wrap:wrap;margin:.55rem 0;}
            .kx-role{font-size:.62rem;font-weight:600;padding:.12rem .4rem;border-radius:.35rem;background:var(--kx-soft);color:var(--kx-muted);letter-spacing:.02em;}
            .kx-node-bar{height:6px;border-radius:99px;background:var(--kx-soft);overflow:hidden;display:flex;margin-top:.55rem;}
            .kx-node-bar>i{display:block;height:100%;}
            .kx-node-foot{display:flex;gap:.9rem;margin-top:.6rem;margin-bottom:.1rem;font-size:.72rem;color:var(--kx-muted);}
            .kx-node-foot b{color:var(--kx-fg);font-weight:640;}
            .kx-node-off{font-size:.72rem;color:var(--kx-off);font-weight:600;margin-top:.55rem;}
        </style>

        {{-- header --}}
        <div class="kx-head">
            <div class="kx-title"><span class="kx-live"></span>{{ $label }}</div>
            <div class="kx-meta">live · refreshed {{ $generatedAt }}</div>
        </div>

        @if (! empty($errors))
            <div class="kx-err">Couldn't reach: {{ implode(', ', $errors) }}. Showing what's available.</div>
        @endif

        {{-- stat cards --}}
        <div class="kx-stats">
            <div class="kx-stat" style="--accent:var(--kx-accent)">
                <div class="kx-stat-label">Instances</div>
                <div class="kx-stat-num">{{ $total }}</div>
                <div class="kx-stat-sub">{{ $containers }} CT · {{ $vms }} VM</div>
            </div>
            <div class="kx-stat" style="--accent:var(--kx-run)">
                <div class="kx-stat-label">Running</div>
                <div class="kx-stat-num">{{ $running }}</div>
                <div class="kx-stat-sub">{{ $runPct }}% of fleet</div>
            </div>
            <div class="kx-stat" style="--accent:var(--kx-stop)">
                <div class="kx-stat-label">Stopped</div>
                <div class="kx-stat-num">{{ $stopped }}</div>
                <div class="kx-stat-sub">{{ $other ? $other.' other' : 'idle' }}</div>
            </div>
            <div class="kx-stat" style="--accent:{{ $nodesOnline === $nodesTotal ? 'var(--kx-run)' : 'var(--kx-off)' }}">
                <div class="kx-stat-label">Nodes online</div>
                <div class="kx-stat-num">{{ $nodesOnline }}</div>
                <div class="kx-stat-sub">of {{ $nodesTotal }} node{{ $nodesTotal === 1 ? '' : 's' }}</div>
            </div>
        </div>

        {{-- fleet health bar --}}
        <div class="kx-fleet">
            <div class="kx-fleet-top"><span>Fleet health</span><span>{{ $total }} total</span></div>
            <div class="kx-bar">
                @if ($running)<i style="width:{{ $runPct }}%;min-width:3px;background:var(--kx-run)"></i>@endif
                @if ($stopped)<i style="width:{{ $stopPct }}%;min-width:3px;background:var(--kx-stop)"></i>@endif
                @if ($other)<i style="width:{{ $otherPct }}%;min-width:3px;background:var(--kx-warn)"></i>@endif
            </div>
            <div class="kx-legend">
                <div><span class="kx-dot" style="background:var(--kx-run)"></span>Running {{ $running }}</div>
                <div><span class="kx-dot" style="background:var(--kx-stop)"></span>Stopped {{ $stopped }}</div>
                @if ($other)<div><span class="kx-dot" style="background:var(--kx-warn)"></span>Other {{ $other }}</div>@endif
            </div>
        </div>

        {{-- per-node grid --}}
        <div class="kx-section-label">Nodes</div>
        <div class="kx-nodes">
            @foreach ($nodeCards as $n)
                <div class="kx-node">
                    <div class="kx-node-head">
                        <span class="kx-node-dot" style="background:{{ $n['online'] ? 'var(--kx-run)' : 'var(--kx-off)' }}"></span>
                        <span class="kx-node-name">{{ $n['name'] }}</span>
                        <span class="kx-node-count">{{ $n['total'] }} inst</span>
                    </div>

                    @if (! empty($n['roleLabels']))
                        <div class="kx-roles">
                            @foreach ($n['roleLabels'] as $r)
                                <span class="kx-role">{{ $r }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if ($n['online'])
                        <div class="kx-node-bar">
                            @if ($n['running'])<i style="width:{{ $n['total'] ? round($n['running'] / $n['total'] * 100, 1) : 0 }}%;min-width:3px;background:var(--kx-run)"></i>@endif
                            @if ($n['stopped'])<i style="width:{{ $n['total'] ? round($n['stopped'] / $n['total'] * 100, 1) : 0 }}%;min-width:3px;background:var(--kx-stop)"></i>@endif
                            @if ($n['other'])<i style="width:{{ $n['total'] ? round($n['other'] / $n['total'] * 100, 1) : 0 }}%;min-width:3px;background:var(--kx-warn)"></i>@endif
                        </div>
                        <div class="kx-node-foot">
                            <span><b>{{ $n['running'] }}</b> up</span>
                            <span><b>{{ $n['stopped'] }}</b> down</span>
                            <span>{{ $n['containers'] }} CT · {{ $n['vms'] }} VM</span>
                        </div>
                        @if ($n['state'])
                            @php
                                $st = $n['state'];
                                $ramColor = $st['ram_pct'] >= 85 ? 'var(--kx-off)' : ($st['ram_pct'] >= 65 ? 'var(--kx-warn)' : 'var(--kx-run)');
                                $poolColor = $st['pool_pct'] >= 90 ? 'var(--kx-off)' : ($st['pool_pct'] >= 75 ? 'var(--kx-warn)' : 'var(--kx-run)');
                                $fmtGiB = fn ($b) => number_format($b / 1073741824, $b < 10737418240 ? 1 : 0).'G';
                                $load1 = $st['load'][0] ?? 0;
                                $loadColor = $load1 >= 8 ? 'var(--kx-off)' : ($load1 >= 4 ? 'var(--kx-warn)' : 'var(--kx-fg)');
                            @endphp
                            <div class="kx-node-res">
                                <div class="kx-res-row">
                                    <div class="kx-res-top">
                                        <span>Memory · {{ $st['ram_pct'] }}%</span>
                                        <span><b>{{ $fmtGiB($st['ram_used']) }}</b> used of {{ $fmtGiB($st['ram_total']) }}</span>
                                    </div>
                                    <div class="kx-res-bar"><i style="width:{{ max(2, $st['ram_pct']) }}%;background:{{ $ramColor }}"></i></div>
                                </div>
                                @if ($st['pool_total'] > 0)
                                    <div class="kx-res-row">
                                        <div class="kx-res-top">
                                            <span>{{ $st['pool_name'] }} · {{ $st['pool_pct'] }}%</span>
                                            <span><b>{{ $fmtGiB($st['pool_used']) }}</b> used of {{ $fmtGiB($st['pool_total']) }}</span>
                                        </div>
                                        <div class="kx-res-bar"><i style="width:{{ max(2, $st['pool_pct']) }}%;background:{{ $poolColor }}"></i></div>
                                    </div>
                                @endif
                                <div class="kx-load">
                                    <span class="kx-load-label">Load</span>
                                    <span class="kx-load-val" style="color:{{ $loadColor }}">{{ number_format($load1, 2) }}</span>
                                    <span class="kx-load-rest">· {{ number_format($st['load'][1] ?? 0, 2) }} · {{ number_format($st['load'][2] ?? 0, 2) }}</span>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="kx-node-off">{{ $n['status'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
