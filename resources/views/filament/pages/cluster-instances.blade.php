<x-filament-panels::page>
    <div x-data="clusterView(@js($clusters), @js($members), @js($instances))">

        {{-- Cluster chips (level 1) --}}
        <div style="margin-bottom:1rem;">
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;opacity:.5;margin-bottom:.5rem;">Clusters</div>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                <template x-for="c in clusters" :key="c.key">
                    <button @click="toggleCluster(c.key)" :style="chipStyle(clusterActive(c.key), '#f59e0b')" x-text="c.label"></button>
                </template>
            </div>
        </div>

        {{-- Node cards (level 2) --}}
        <div style="margin-bottom:1.25rem;">
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;opacity:.5;margin-bottom:.5rem;">Nodes</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.75rem;">
                <template x-for="n in visibleNodes" :key="n.cluster + '/' + n.name">
                    <div @click="toggleNode(n.name)"
                        style="cursor:pointer;border-radius:.6rem;padding:.85rem 1rem;transition:border-color .1s;border:1px solid;"
                        :style="nodeActive(n.name) ? 'border-color:#22c55e;background:rgba(34,197,94,.06);' : 'border-color:#27272a;background:transparent;'">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:.45rem;font-weight:600;">
                                <span style="width:.5rem;height:.5rem;border-radius:9999px;" :style="'background:' + (n.status === 'Online' ? '#22c55e' : '#ef4444')"></span>
                                <span x-text="n.name"></span>
                            </div>
                            <span style="font-size:.75rem;opacity:.55;" x-text="n.count + ' inst.'"></span>
                        </div>
                        <div style="font-family:monospace;font-size:.8rem;opacity:.7;margin-top:.4rem;">
                            <span x-text="n.host"></span><span style="opacity:.45;" x-text="n.port ? ':' + n.port : ''"></span>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.5rem;" x-show="n.roles && n.roles.length">
                            <template x-for="role in n.roles" :key="role">
                                <span style="font-size:.65rem;padding:.1rem .4rem;border-radius:.3rem;background:rgba(255,255,255,.05);opacity:.7;" x-text="role"></span>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Search + clear --}}
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;">
            <input type="text" x-model="search" placeholder="Search instances…"
                style="flex:1;padding:.55rem .9rem;border-radius:.5rem;border:1px solid #3f3f46;background:transparent;color:inherit;font-size:.9rem;">
            <span style="opacity:.5;font-size:.85rem;white-space:nowrap;" x-text="filtered.length + ' shown'"></span>
            <button x-show="selectedClusters.length || selectedNodes.length || search" @click="clearAll()"
                style="opacity:.6;font-size:.85rem;cursor:pointer;background:none;border:none;color:inherit;text-decoration:underline;">clear</button>
        </div>

        {{-- Table --}}
        <div style="border:1px solid #27272a;border-radius:.75rem;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                <thead>
                    <tr style="text-align:left;background:rgba(255,255,255,.02);">
                        <template x-for="col in columns" :key="col.field">
                            <th @click="sortBy(col.field)" style="padding:.7rem 1rem;font-weight:500;opacity:.6;cursor:pointer;user-select:none;white-space:nowrap;">
                                <span x-text="col.label"></span>
                                <span x-show="sortField === col.field" x-text="sortAsc ? ' ↑' : ' ↓'" style="opacity:.8;"></span>
                            </th>
                        </template>
                        <th style="padding:.7rem 1rem;font-weight:500;opacity:.6;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="i in filtered" :key="i.cluster + '/' + i.name">
                        <tr style="border-top:1px solid #27272a;">
                            <td style="padding:.7rem 1rem;font-weight:600;" x-text="i.name"></td>
                            <td style="padding:.7rem 1rem;opacity:.7;" x-text="i.cluster_label"></td>
                            <td style="padding:.7rem 1rem;opacity:.7;" x-text="i.node"></td>
                            <td style="padding:.7rem 1rem;">
                                <span style="font-size:.75rem;padding:.15rem .5rem;border-radius:.35rem;border:1px solid #3f3f46;opacity:.8;" x-text="i.type === 'virtual-machine' ? 'VM' : 'Container'"></span>
                            </td>
                            <td style="padding:.7rem 1rem;">
                                <span style="display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;">
                                    <span style="width:.5rem;height:.5rem;border-radius:9999px;" :style="'background:' + (i.status === 'Running' ? '#22c55e' : '#71717a')"></span>
                                    <span x-text="i.status"></span>
                                </span>
                            </td>
                            <td style="padding:.7rem 1rem;font-family:monospace;opacity:.8;" x-text="i.ipv4 || '—'"></td>
                            <td style="padding:.7rem 1rem;">
                                <div x-show="pending !== i.cluster + '/' + i.name" style="display:flex;gap:.4rem;">
                                    <button x-show="i.status !== 'Running'" @click="act('start', i)" :style="btn('#22c55e')">Start</button>
                                    <button x-show="i.status === 'Running'" @click="act('restart', i)" :style="btn('#a1a1aa')">Restart</button>
                                    <button x-show="i.status === 'Running'" @click="act('stop', i)" :style="btn('#ef4444')">Stop</button>
                                </div>
                                <span x-show="pending === i.cluster + '/' + i.name" style="opacity:.5;font-size:.8rem;">working…</span>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="filtered.length === 0">
                        <td colspan="7" style="padding:2rem;text-align:center;opacity:.5;">No instances match.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function clusterView(clusters, members, instances) {
            return {
                clusters, members, instances,
                selectedClusters: [], selectedNodes: [], search: '',
                sortField: 'name', sortAsc: true, pending: null,
                columns: [
                    { field: 'name', label: 'Name' }, { field: 'cluster_label', label: 'Cluster' },
                    { field: 'node', label: 'Node' }, { field: 'type', label: 'Type' },
                    { field: 'status', label: 'Status' }, { field: 'ipv4', label: 'IPv4' },
                ],

                clusterActive(k) { return this.selectedClusters.length === 0 || this.selectedClusters.includes(k); },
                nodeActive(n) { return this.selectedNodes.length === 0 || this.selectedNodes.includes(n); },
                toggleCluster(k) {
                    const i = this.selectedClusters.indexOf(k);
                    i > -1 ? this.selectedClusters.splice(i, 1) : this.selectedClusters.push(k);
                    const valid = this.visibleNodes.map(n => n.name);
                    this.selectedNodes = this.selectedNodes.filter(n => valid.includes(n));
                },
                toggleNode(n) {
                    const i = this.selectedNodes.indexOf(n);
                    i > -1 ? this.selectedNodes.splice(i, 1) : this.selectedNodes.push(n);
                },
                clearAll() { this.selectedClusters = []; this.selectedNodes = []; this.search = ''; },
                sortBy(f) { this.sortField === f ? (this.sortAsc = !this.sortAsc) : (this.sortField = f, this.sortAsc = true); },

                fuzzy(needle, hay) {
                    needle = (needle || '').toLowerCase(); hay = (hay || '').toLowerCase();
                    let i = 0; for (const c of hay) if (i < needle.length && c === needle[i]) i++;
                    return i === needle.length;
                },
                get visibleNodes() {
                    return this.members.filter(m => this.selectedClusters.length === 0 || this.selectedClusters.includes(m.cluster));
                },
                chipStyle(active, color) {
                    return `padding:.35rem .8rem;border-radius:9999px;font-size:.85rem;font-weight:500;cursor:pointer;`
                        + `border:1px solid ${active ? color : '#3f3f46'};background:${active ? color + '1f' : 'transparent'};color:${active ? color : 'inherit'};`;
                },
                btn(color) {
                    return `font-size:.75rem;padding:.2rem .6rem;border-radius:.35rem;cursor:pointer;`
                        + `border:1px solid ${color}66;background:${color}14;color:${color};`;
                },

                async act(verb, i) {
                    if (!confirm(verb.charAt(0).toUpperCase() + verb.slice(1) + ' “' + i.name + '”?')) return;
                    this.pending = i.cluster + '/' + i.name;
                    try {
                        await this.$wire.runAction(i.cluster, i.name, verb);
                        const fresh = await this.$wire.get('instances');
                        if (Array.isArray(fresh)) this.instances = fresh;
                    } finally {
                        this.pending = null;
                    }
                },

                get filtered() {
                    let out = this.instances.filter(i =>
                        (this.selectedClusters.length === 0 || this.selectedClusters.includes(i.cluster)) &&
                        (this.selectedNodes.length === 0 || this.selectedNodes.includes(i.node)) &&
                        this.fuzzy(this.search, i.name));
                    const f = this.sortField, dir = this.sortAsc ? 1 : -1;
                    return out.sort((a, b) => String(a[f] ?? '').localeCompare(String(b[f] ?? ''), undefined, { numeric: true }) * dir);
                },
            };
        }
    </script>
</x-filament-panels::page>
