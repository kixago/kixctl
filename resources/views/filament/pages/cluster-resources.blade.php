<x-filament-panels::page>
    <div wire:poll.30s="loadData"></div>

    <div wire:ignore x-data="resourcesView(@js($clusters), @js($pools), @js($volumes), @js($networks), @js($profiles))">

        {{-- Cluster chips --}}
        <div style="margin-bottom:1rem;">
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;opacity:.5;margin-bottom:.5rem;">
                Clusters</div>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                <template x-for="c in clusters" :key="c.key">
                    <button @click="toggleCluster(c.key)"
                        :style="chipStyle(clusterActive(c.key), c.reachable ? '#f59e0b' : '#71717a') + (c.reachable ? '' :
                            'opacity:.45;')"
                        :title="chipTitle(c)">
                        <span x-text="c.label"></span>
                        <span x-show="!c.reachable" style="margin-left:.35rem;">⚠</span>
                        <span x-show="c.reachable && c.partial && c.partial.length"
                            style="margin-left:.35rem;color:#f59e0b;" title="">◐</span>
                    </button>
                </template>
            </div>
        </div>

        {{-- Tabs --}}
        <div style="display:flex;gap:.25rem;border-bottom:1px solid #27272a;margin-bottom:1rem;">
            <template x-for="t in tabs" :key="t.key">
                <button @click="switchTab(t.key)"
                    style="padding:.55rem 1rem;font-size:.9rem;background:none;border:none;color:inherit;cursor:pointer;border-bottom:2px solid transparent;"
                    :style="tab === t.key ? 'border-bottom-color:#f59e0b;opacity:1;font-weight:600;' : 'opacity:.55;'">
                    <span x-text="t.label"></span>
                    <span style="margin-left:.3rem;font-size:.75rem;opacity:.6;"
                        x-text="'(' + countFor(t.key) + ')'"></span>
                </button>
            </template>
        </div>

        {{-- Volume type filter (volumes tab only) --}}
        <div x-show="tab === 'volumes'" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
            <template x-for="vt in volumeTypes" :key="vt.key">
                <button @click="volType = (volType === vt.key ? '' : vt.key)"
                    :style="chipStyle(volType === vt.key, '#38bdf8')">
                    <span x-text="vt.label"></span>
                </button>
            </template>
        </div>

        {{-- Notices for clusters that could not serve this tab's data. Follows
             the chip selection; collapsed to one line each, expanded on demand. --}}
        <template x-for="p in partialNotices" :key="p.key">
            <div
                style="margin-bottom:1rem;padding:.6rem .9rem;border:1px solid rgba(245,158,11,.4);border-radius:.5rem;background:rgba(245,158,11,.07);font-size:.85rem;">
                <div style="display:flex;align-items:baseline;gap:.5rem;">
                    <span style="color:#f59e0b;">◐</span>
                    <span style="flex:1;">
                        <span style="font-weight:600;" x-text="p.label"></span>
                        <span style="opacity:.85;" x-text="' — ' + p.summary"></span>
                    </span>
                    <button @click="toggleNotice(p.key)"
                        style="background:none;border:none;color:inherit;cursor:pointer;font-size:.8rem;opacity:.6;text-decoration:underline;white-space:nowrap;"
                        x-text="noticeOpen(p.key) ? 'hide' : 'why?'"></button>
                </div>
                <div
                    :style="'display:grid;transition:grid-template-rows .3s ease, opacity .3s ease;' +
                    'grid-template-rows:' + (noticeOpen(p.key) ? '1fr' : '0fr') + ';' +
                    'opacity:' + (noticeOpen(p.key) ? '.8' : '0') + ';'">
                    <div style="overflow:hidden;min-height:0;">
                        <div style="margin-top:.5rem;padding-left:1.35rem;line-height:1.5;" x-text="p.detail"></div>
                    </div>
                </div>
            </div>
        </template>

        {{-- Search + count + clear --}}
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;">
            <input type="text" x-model="search" :placeholder="'Search ' + tab + '…'"
                style="flex:1;padding:.55rem .9rem;border-radius:.5rem;border:1px solid #3f3f46;background:transparent;color:inherit;font-size:.9rem;">
            <span style="opacity:.5;font-size:.85rem;white-space:nowrap;" x-text="filtered.length + ' shown'"></span>
            <button x-show="selectedClusters.length || search || volType" @click="clearAll()"
                style="opacity:.6;font-size:.85rem;cursor:pointer;background:none;border:none;color:inherit;text-decoration:underline;">clear</button>
        </div>

        {{-- Table --}}
        <div style="border:1px solid #27272a;border-radius:.75rem;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                <thead>
                    <tr style="text-align:left;background:rgba(255,255,255,.02);">
                        <template x-for="col in columns" :key="col.field">
                            <th @click="sortBy(col.field)"
                                style="padding:.7rem 1rem;font-weight:500;opacity:.6;cursor:pointer;user-select:none;white-space:nowrap;">
                                <span x-text="col.label"></span>
                                <span x-show="sortField === col.field" x-text="sortAsc ? ' ↑' : ' ↓'"></span>
                            </th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in filtered" :key="rowKey(row)">
                        <tr style="border-top:1px solid #27272a;"
                            :style="tab === 'profiles' && row.used_by >= 10 ? 'background:rgba(245,158,11,.06);' : ''">
                            <template x-for="col in columns" :key="col.field">
                                <td style="padding:.65rem 1rem;white-space:nowrap;">
                                    {{-- managed / unmanaged badge --}}
                                    <template x-if="col.field === 'managed'">
                                        <span style="font-size:.72rem;padding:.15rem .5rem;border-radius:.35rem;"
                                            :style="row.managed ?
                                                'background:rgba(34,197,94,.12);color:#4ade80;' :
                                                'background:rgba(255,255,255,.05);opacity:.6;'"
                                            x-text="row.managed ? 'managed' : 'observed'"></span>
                                    </template>
                                    {{-- devices chip list --}}
                                    <template x-if="col.field === 'devices'">
                                        <span style="display:inline-flex;flex-wrap:wrap;gap:.3rem;">
                                            <template x-for="d in (row.devices || [])" :key="d">
                                                <span
                                                    style="font-size:.68rem;padding:.1rem .4rem;border-radius:.3rem;background:rgba(255,255,255,.05);opacity:.75;"
                                                    x-text="d"></span>
                                            </template>
                                        </span>
                                    </template>
                                    {{-- used_by, with a caution on widely shared profiles --}}
                                    <template x-if="col.field === 'used_by'">
                                        <span>
                                            <span x-text="row.used_by"></span>
                                            <span x-show="tab === 'profiles' && row.used_by >= 10"
                                                :title="row.used_by +
                                                    ' instances inherit this profile; editing it changes all of them'"
                                                style="margin-left:.35rem;color:#f59e0b;">⚠ shared widely</span>
                                        </span>
                                    </template>
                                    {{-- plain fields --}}
                                    <template x-if="!['managed', 'devices', 'used_by'].includes(col.field)">
                                        <span x-text="row[col.field] ?? ''"
                                            :style="col.field === 'name' ? 'font-weight:600;' : 'opacity:.8;'"></span>
                                    </template>
                                </td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="!filtered.length" style="border-top:1px solid #27272a;">
                        <td :colspan="columns.length" style="padding:1.2rem 1rem;opacity:.5;text-align:center;"
                            x-text="emptyMessage"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top:.75rem;font-size:.78rem;opacity:.45;">
            Read-only view. Management actions arrive per resource, each behind its own permission.
        </div>
    </div>

    <script>
        function resourcesView(clusters, pools, volumes, networks, profiles) {
            return {
                clusters,
                pools,
                volumes,
                networks,
                profiles,
                tab: 'volumes',
                search: '',
                volType: 'custom',
                selectedClusters: [],
                openNotices: [],
                sortField: 'name',
                sortAsc: true,

                tabs: [{
                        key: 'volumes',
                        label: 'Volumes'
                    },
                    {
                        key: 'networks',
                        label: 'Networks'
                    },
                    {
                        key: 'profiles',
                        label: 'Profiles'
                    },
                    {
                        key: 'pools',
                        label: 'Pools'
                    },
                ],

                volumeTypes: [{
                        key: 'custom',
                        label: 'custom volumes'
                    },
                    {
                        key: 'container',
                        label: 'container root disks'
                    },
                    {
                        key: 'virtual-machine',
                        label: 'VM root disks'
                    },
                    {
                        key: 'image',
                        label: 'image cache'
                    },
                ],

                columnSets: {
                    volumes: [{
                            field: 'name',
                            label: 'Name'
                        },
                        {
                            field: 'pool',
                            label: 'Pool'
                        },
                        {
                            field: 'type',
                            label: 'Type'
                        },
                        {
                            field: 'content_type',
                            label: 'Content'
                        },
                        {
                            field: 'node',
                            label: 'Node'
                        },
                        {
                            field: 'cluster_label',
                            label: 'Cluster'
                        },
                        {
                            field: 'used_by',
                            label: 'Used by'
                        },
                    ],
                    networks: [{
                            field: 'name',
                            label: 'Name'
                        },
                        {
                            field: 'type',
                            label: 'Type'
                        },
                        {
                            field: 'managed',
                            label: 'Managed'
                        },
                        {
                            field: 'cluster_label',
                            label: 'Cluster'
                        },
                        {
                            field: 'used_by',
                            label: 'Used by'
                        },
                    ],
                    profiles: [{
                            field: 'name',
                            label: 'Name'
                        },
                        {
                            field: 'description',
                            label: 'Description'
                        },
                        {
                            field: 'devices',
                            label: 'Devices'
                        },
                        {
                            field: 'cluster_label',
                            label: 'Cluster'
                        },
                        {
                            field: 'used_by',
                            label: 'Used by'
                        },
                    ],
                    pools: [{
                            field: 'name',
                            label: 'Name'
                        },
                        {
                            field: 'driver',
                            label: 'Driver'
                        },
                        {
                            field: 'status',
                            label: 'Status'
                        },
                        {
                            field: 'cluster_label',
                            label: 'Cluster'
                        },
                        {
                            field: 'used_by',
                            label: 'Used by'
                        },
                    ],
                },

                get columns() {
                    return this.columnSets[this.tab];
                },

                get rows() {
                    return {
                        volumes: this.volumes,
                        networks: this.networks,
                        profiles: this.profiles,
                        pools: this.pools
                    } [this.tab];
                },

                countFor(key) {
                    return {
                        volumes: this.volumes,
                        networks: this.networks,
                        profiles: this.profiles,
                        pools: this.pools
                    } [key].length;
                },

                // Notices for the current tab, limited to the selected clusters
                // when a chip selection is active.
                get partialNotices() {
                    return this.clusters
                        .filter(c => !this.selectedClusters.length || this.selectedClusters.includes(c.key))
                        .flatMap(c =>
                            (c.partial || [])
                            .filter(p => (p.tabs || []).includes(this.tab))
                            .map(p => ({
                                key: c.key + '/' + p.what,
                                label: c.label,
                                summary: p.summary || '',
                                detail: p.detail || '',
                            }))
                        );
                },

                toggleNotice(key) {
                    const i = this.openNotices.indexOf(key);
                    if (i === -1) this.openNotices.push(key);
                    else this.openNotices.splice(i, 1);
                },

                noticeOpen(key) {
                    return this.openNotices.includes(key);
                },

                get filtered() {
                    let list = this.rows;

                    if (this.selectedClusters.length) {
                        list = list.filter(r => this.selectedClusters.includes(r.cluster));
                    }
                    if (this.tab === 'volumes' && this.volType) {
                        list = list.filter(r => r.type === this.volType);
                    }
                    if (this.search) {
                        const q = this.search.toLowerCase();
                        list = list.filter(r => Object.values(r).some(v =>
                            (typeof v === 'string' && v.toLowerCase().includes(q))
                        ));
                    }

                    const f = this.sortField,
                        asc = this.sortAsc ? 1 : -1;
                    return [...list].sort((a, b) => {
                        const av = a[f] ?? '',
                            bv = b[f] ?? '';
                        if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * asc;
                        return String(av).localeCompare(String(bv)) * asc;
                    });
                },

                get emptyMessage() {
                    if (this.tab === 'volumes' && this.volType === 'custom' && !this.search && !this.selectedClusters
                        .length) {
                        return 'No custom volumes yet. The other volume types are instance root disks and cached images, which Incus manages through their instances. Creating a custom volume, an attachable data disk, will be the first management action on this page.';
                    }
                    return 'Nothing matches.';
                },

                rowKey(row) {
                    // Volumes are keyed by pool, name, and node: the same name
                    // can exist on several members with local storage drivers.
                    if (this.tab === 'volumes') {
                        return [row.cluster, row.pool, row.type, row.name, row.node].join('/');
                    }
                    return row.cluster + '/' + row.name;
                },

                switchTab(key) {
                    this.tab = key;
                    this.sortField = 'name';
                    this.sortAsc = true;
                    this.search = '';
                    this.volType = 'custom';
                },

                sortBy(field) {
                    if (this.sortField === field) {
                        this.sortAsc = !this.sortAsc;
                        return;
                    }
                    this.sortField = field;
                    this.sortAsc = true;
                },

                toggleCluster(key) {
                    const i = this.selectedClusters.indexOf(key);
                    if (i === -1) this.selectedClusters.push(key);
                    else this.selectedClusters.splice(i, 1);
                },

                clusterActive(key) {
                    return this.selectedClusters.includes(key);
                },

                clearAll() {
                    this.selectedClusters = [];
                    this.search = '';
                    this.volType = '';
                },

                chipTitle(c) {
                    if (!c.reachable) return 'Unreachable: ' + (c.error || 'connection failed');
                    if (c.partial && c.partial.length) {
                        return c.partial.map(p => p.summary + ' See the affected tab.').join('\n');
                    }
                    return '';
                },

                chipStyle(active, color) {
                    return 'padding:.35rem .8rem;border-radius:9999px;font-size:.85rem;cursor:pointer;border:1px solid;' +
                        (active ?
                            'border-color:' + color + ';background:' + color + '1a;color:' + color + ';' :
                            'border-color:#3f3f46;background:transparent;color:inherit;opacity:.75;');
                },

                init() {
                    window.addEventListener('resources-changed', async () => {
                        // The only data path into this wire:ignore root.
                        this.clusters = await this.$wire.get('clusters');
                        this.pools = await this.$wire.get('pools');
                        this.volumes = await this.$wire.get('volumes');
                        this.networks = await this.$wire.get('networks');
                        this.profiles = await this.$wire.get('profiles');
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
