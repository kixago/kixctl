@php
    $inputStyle =
        'width:100%;padding:.55rem .9rem;border-radius:.5rem;border:1px solid #3f3f46;background:transparent;color:inherit;font-size:.9rem;';
    $labelStyle =
        'display:block;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;opacity:.55;margin-bottom:.3rem;';
@endphp

<div style="margin-bottom:1.25rem;">
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
    @if (!$open)
        <button type="button" wire:click="$set('open', true)"
            style="width:100%;padding:.7rem 1rem;border-radius:.6rem;border:1px dashed #3f3f46;background:transparent;color:inherit;cursor:pointer;font-size:.9rem;opacity:.8;display:flex;align-items:center;justify-content:center;gap:.5rem;">
            <span style="font-size:1.1rem;line-height:1;">＋</span> Create instance
        </button>
    @else
        <div style="border:1px solid #27272a;border-radius:.75rem;padding:1.25rem;background:rgba(255,255,255,.015);">

            @if (!$creating)
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                    <div style="font-weight:600;font-size:1rem;">Create instance</div>
                    <button type="button" wire:click="$set('open', false)"
                        style="background:none;border:none;color:inherit;opacity:.5;cursor:pointer;font-size:1.2rem;line-height:1;">✕</button>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                    {{-- Name --}}
                    <div>
                        <label style="{{ $labelStyle }}">Name</label>
                        <input type="text" wire:model="name" placeholder="my-service" style="{{ $inputStyle }}">
                        @error('name')
                            <span style="color:#ef4444;font-size:.75rem;">{{ $message }}</span>
                        @enderror
                    </div>

                    {{-- Type --}}
                    <div>
                        <label style="{{ $labelStyle }}">Type</label>
                        <select wire:model.live="type" style="{{ $inputStyle }}">
                            <option value="container">Container</option>
                            <option value="virtual-machine">Virtual Machine</option>
                        </select>
                    </div>

                    {{-- Node --}}
                    <div>
                        <label style="{{ $labelStyle }}">Node</label>
                        <select wire:model="target" style="{{ $inputStyle }}">
                            @foreach ($nodeOptions as $val => $lbl)
                                <option value="{{ $val }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                        @error('target')
                            <span style="color:#ef4444;font-size:.75rem;">{{ $message }}</span>
                        @enderror
                    </div>

                    {{-- Architecture --}}
                    <div>
                        <label style="{{ $labelStyle }}">Architecture</label>
                        <select wire:model.live="arch" style="{{ $inputStyle }}">
                            @foreach ($archOptions as $a)
                                <option value="{{ $a }}">{{ $a }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Image (live catalog, fuzzy combobox) --}}
                    <div style="grid-column:1/-1;" x-data="imagePicker(@js($this->imageList))">
                        <label style="{{ $labelStyle }}">
                            Image
                            <span style="opacity:.45;text-transform:none;letter-spacing:0;"
                                x-text="'· ' + images.length + ' available'"></span>
                        </label>

                        @if ($useCustomImage)
                            {{-- custom alias mode --}}
                            <input type="text" wire:model="imageCustom"
                                placeholder="e.g. ubuntu/25.04 or a local alias" style="{{ $inputStyle }}">
                        @else
                            {{-- combobox mode --}}
                            <div style="position:relative;" @click.outside="openList = false">
                                <input type="text" x-model="query" @focus="openList = true"
                                    @keydown.escape="openList = false" @keydown.arrow-down.prevent="move(1)"
                                    @keydown.arrow-up.prevent="move(-1)"
                                    @keydown.enter.prevent="choose(filtered[highlight])"
                                    :placeholder="selectedAlias ? selectedLabel : 'Type to search images…'"
                                    style="{{ $inputStyle }}max-width:32rem;">

                                {{-- selected chip when not actively searching --}}
                                <div x-show="selectedAlias && !openList && !query"
                                    style="position:absolute;top:50%;left:.9rem;transform:translateY(-50%);font-size:.9rem;pointer-events:none;"
                                    x-text="selectedLabel"></div>

                                {{-- results panel --}}
                                <div x-show="openList" x-cloak x-transition.opacity.duration.100ms
                                    style="position:absolute;z-index:30;top:calc(100% + .3rem);left:0;right:0;max-width:32rem;max-height:16rem;overflow-y:auto;border:1px solid #3f3f46;border-radius:.5rem;background:#18181b;box-shadow:0 10px 30px rgba(0,0,0,.4);">
                                    <template x-for="(img, idx) in filtered" :key="img.alias">
                                        <div @click="choose(img)" @mouseenter="highlight = idx"
                                            :style="'padding:.5rem .8rem;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:1rem;' +
                                            (highlight === idx ? 'background:rgba(99,102,241,.18);' : '')">
                                            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                                x-text="img.label"></span>
                                            <span
                                                style="font-family:monospace;font-size:.72rem;opacity:.5;white-space:nowrap;"
                                                x-text="img.sub"></span>
                                        </div>
                                    </template>
                                    <div x-show="filtered.length === 0"
                                        style="padding:.7rem .8rem;opacity:.5;font-size:.85rem;">No images match.</div>
                                </div>
                            </div>
                        @endif

                        <div style="display:flex;gap:.75rem;margin-top:.35rem;">
                            <button type="button" wire:click="$toggle('useCustomImage')"
                                style="background:none;border:none;color:inherit;opacity:.6;cursor:pointer;font-size:.72rem;text-decoration:underline;padding:0;">
                                {{ $useCustomImage ? 'pick from list' : 'enter custom alias' }}
                            </button>
                            <button type="button" wire:click="loadCatalog(true)" wire:loading.attr="disabled"
                                wire:target="loadCatalog"
                                style="background:none;border:none;color:inherit;opacity:.6;cursor:pointer;font-size:.72rem;text-decoration:underline;padding:0;">
                                <span wire:loading.remove wire:target="loadCatalog">refresh catalog</span>
                                <span wire:loading wire:target="loadCatalog">refreshing…</span>
                            </button>
                        </div>
                    </div>

                    {{-- Image source (remote vs local cache) --}}
                    <div>
                        <label style="{{ $labelStyle }}">Source</label>
                        <select wire:model="imageScope" style="{{ $inputStyle }}">
                            <option value="remote">Remote (pull &amp; cache)</option>
                            <option value="local">Local (already on cluster)</option>
                        </select>
                    </div>
                </div>

                {{-- Profiles --}}
                <div style="margin-top:1rem;">
                    <label style="{{ $labelStyle }}">Profiles</label>
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                        @foreach ($profileOptions as $p => $lbl)
                            @php $on = in_array($p, $profiles, true); @endphp
                            <button type="button" wire:click="toggleProfile('{{ $p }}')"
                                style="padding:.35rem .8rem;border-radius:9999px;font-size:.85rem;cursor:pointer;border:1px solid {{ $on ? '#22c55e' : '#3f3f46' }};background:{{ $on ? 'rgba(34,197,94,.12)' : 'transparent' }};color:{{ $on ? '#22c55e' : 'inherit' }};">
                                {{ $lbl }}
                            </button>
                        @endforeach
                    </div>
                    @error('profiles')
                        <span style="color:#ef4444;font-size:.75rem;">{{ $message }}</span>
                    @enderror
                    <div style="font-size:.72rem;opacity:.5;margin-top:.4rem;">The "power" profile supplies root disk +
                        eth0 on br0.</div>
                </div>

                {{-- Advanced toggle --}}
                <div style="margin-top:1rem;">
                    <button type="button" wire:click="$toggle('showAdvanced')"
                        style="background:none;border:none;color:inherit;opacity:.7;cursor:pointer;font-size:.85rem;padding:0;">
                        {{ $showAdvanced ? '▾' : '▸' }} Advanced
                    </button>
                </div>

                @if ($showAdvanced)
                    <div
                        style="margin-top:.75rem;padding-top:1rem;border-top:1px solid #27272a;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                        <div>
                            <label style="{{ $labelStyle }}">CPU limit</label>
                            <input type="text" wire:model="limitsCpu" placeholder="2" style="{{ $inputStyle }}">
                            <div style="font-size:.7rem;opacity:.45;margin-top:.25rem;">Count (2) or pin (0-3). Blank =
                                unlimited.</div>
                        </div>
                        <div>
                            <label style="{{ $labelStyle }}">Memory limit</label>
                            <input type="text" wire:model="limitsMemory" placeholder="4GiB"
                                style="{{ $inputStyle }}">
                            <div style="font-size:.7rem;opacity:.45;margin-top:.25rem;">e.g. 2GiB, 4096MiB, 25%. Blank
                                = unlimited.</div>
                        </div>
                        <div style="grid-column:1/-1;">
                            <label style="{{ $labelStyle }}">Description</label>
                            <input type="text" wire:model="description" placeholder="Optional note"
                                style="{{ $inputStyle }}">
                        </div>
                        <div style="grid-column:1/-1;">
                            <label style="{{ $labelStyle }}">Raw Incus config (one key=value per line)</label>
                            <textarea wire:model="rawConfig" rows="3" placeholder="limits.cpu.priority=5"
                                style="{{ $inputStyle }}resize:vertical;font-family:monospace;"></textarea>
                        </div>
                        <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer;">
                            <input type="checkbox" wire:model="bootAutostart"> Start automatically on host boot
                        </label>
                        @if ($type === 'container')
                            <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer;">
                                <input type="checkbox" wire:model="securityNesting"> Enable nesting (Docker inside)
                            </label>
                        @endif
                    </div>
                @endif

                {{-- Submit row --}}
                <div
                    style="display:flex;align-items:center;gap:1rem;margin-top:1.25rem;padding-top:1rem;border-top:1px solid #27272a;">
                    <button type="button" wire:click="create" wire:loading.attr="disabled" wire:target="create"
                        style="padding:.55rem 1.2rem;border-radius:.5rem;border:1px solid #22c55e66;background:rgba(34,197,94,.12);color:#22c55e;cursor:pointer;font-size:.9rem;font-weight:500;">
                        <span wire:loading.remove wire:target="create">Create instance</span>
                        <span wire:loading wire:target="create">Starting…</span>
                    </button>
                    <label
                        style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer;opacity:.85;">
                        <input type="checkbox" wire:model="startNow"> Start immediately
                    </label>
                </div>
            @else
                {{-- Streaming progress island — pure Alpine, fed only by broadcasts --}}
                <div x-data="createProgress(@js($createToken), @js($name))">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                        <div style="font-weight:600;font-size:1rem;">
                            <span x-show="!terminal">Creating</span>
                            <span x-show="terminal && ok" style="color:#22c55e;">Created</span>
                            <span x-show="terminal && !ok" style="color:#ef4444;">Create failed</span>
                            <span style="font-family:monospace;opacity:.7;margin-left:.35rem;" x-text="name"></span>
                        </div>
                        <button type="button" wire:click="$set('open', false)"
                            style="background:none;border:none;color:inherit;opacity:.5;cursor:pointer;font-size:1.2rem;line-height:1;">✕</button>
                    </div>

                    {{-- indeterminate spinner: pre-download or cached image --}}
                    <div x-show="!terminal && !determinate"
                        style="display:flex;align-items:center;gap:.75rem;padding:.5rem 0;">
                        <span class="kix-spin"></span>
                        <span style="opacity:.8;font-size:.9rem;" x-text="message"></span>
                    </div>

                    {{-- determinate download bar --}}
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
                    <div x-show="terminal && ok"
                        style="display:flex;align-items:center;gap:.6rem;padding:.5rem 0;color:#22c55e;">
                        <span style="font-size:1.2rem;line-height:1;">✓</span>
                        <span style="font-size:.9rem;" x-text="message"></span>
                    </div>

                    {{-- terminal failure --}}
                    <div x-show="terminal && !ok"
                        style="display:flex;align-items:flex-start;gap:.6rem;padding:.5rem 0;color:#ef4444;">
                        <span style="font-size:1.2rem;line-height:1;">✕</span>
                        <span style="font-size:.85rem;" x-text="message"></span>
                    </div>

                    <div x-show="terminal" style="margin-top:1rem;padding-top:1rem;border-top:1px solid #27272a;">
                        <button type="button" wire:click="resetCreate"
                            style="padding:.5rem 1.1rem;border-radius:.5rem;border:1px solid #3f3f46;background:transparent;color:inherit;cursor:pointer;font-size:.85rem;">
                            Create another
                        </button>
                    </div>
                </div>
            @endif

        </div>
    @endif
</div>

<script>
    function imagePicker(images) {
        return {
            images: Array.isArray(images) ? images : [],
            query: '',
            openList: false,
            highlight: 0,
            selectedAlias: '',
            selectedLabel: '',

            init() {
                this.selectedAlias = this.$wire.get('imageAlias') || '';
                this.syncLabel();
                this.$watch('images', () => {
                    this.highlight = 0;
                    this.syncLabel();
                });
            },
            fuzzy(needle, hay) {
                needle = (needle || '').toLowerCase().trim();
                if (!needle) return true;
                hay = (hay || '').toLowerCase();
                return needle.split(/\s+/).every(t => hay.includes(t));
            },
            get filtered() {
                return this.images.filter(i => this.fuzzy(this.query, i.label + ' ' + i.sub));
            },
            move(d) {
                const n = this.filtered.length;
                if (!n) return;
                this.highlight = (this.highlight + d + n) % n;
            },
            choose(img) {
                if (!img) return;
                this.selectedAlias = img.alias;
                this.selectedLabel = img.label;
                this.$wire.set('imageAlias', img.alias);
                this.query = '';
                this.openList = false;
            },
            syncLabel() {
                const m = this.images.find(i => i.alias === this.selectedAlias);
                this.selectedLabel = m ? m.label : '';
            },
        };
    }

    function createProgress(token, name) {
        return {
            token: token,
            name: name,
            phase: 'pending',
            stage: null,
            percent: null,
            rate: null,
            message: 'Requesting create…',
            determinate: false,
            terminal: false,
            ok: false,

            init() {
                if (!window.Echo) {
                    this.message = 'Live updates unavailable (Echo not loaded).';
                    return;
                }
                window.Echo.channel('instance-create.' + this.token)
                    .listen('.progress', (e) => this.onProgress(e));
            },

            onProgress(e) {
                this.phase = e.phase;

                if (e.phase === 'downloading' && e.percent !== null && e.percent !== undefined) {
                    this.determinate = true;
                    this.percent = e.percent;
                    this.stage = e.stage;
                    this.rate = e.rate;
                    this.message = 'Downloading image…';
                } else if (e.phase === 'creating') {
                    this.message = 'Creating instance…';
                    if (this.determinate) this.percent = 100;
                } else if (e.phase === 'starting') {
                    this.message = 'Starting…';
                    if (this.determinate) this.percent = 100;
                } else if (e.phase === 'pending') {
                    this.message = e.message || 'Create accepted…';
                } else if (e.phase === 'done') {
                    this.percent = 100;
                    this.terminal = true;
                    this.ok = true;
                    this.message = e.message || 'Instance ready.';
                    if (window.Livewire) window.Livewire.dispatch('instance-created');
                } else if (e.phase === 'failed') {
                    this.terminal = true;
                    this.ok = false;
                    this.message = e.message || 'Create failed.';
                }
            },
        };
    }
</script>
