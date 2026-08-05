<x-filament-panels::page>
    <div wire:poll.15s="loadData"></div>

    @livewire('instance-detail')

    @livewire('create-instance-form')

    <div wire:ignore wire:key="cluster-instances-view" x-data="clusterView(@js($clusters), @js($members), @js($instances))">

        <div style="margin-bottom:1rem;">
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;opacity:.5;margin-bottom:.5rem;">
                {{ __('common.labels.clusters') }}</div>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                <template x-for="c in clusters" :key="c.key">
                    <button @click="toggleCluster(c.key)"
                        :style="chipStyle(clusterActive(c.key), c.reachable ? '#f59e0b' : '#71717a') + (c.reachable ? '' :
                            'opacity:.45;')"
                        :title="c.reachable ? '' : @js(__('common.status.unreachable')) + ': ' + (c.error ||
                            @js(__('common.status.failed')))">
                        <span x-text="c.label"></span>
                        <span x-show="!c.reachable" style="margin-left:.35rem;">⚠</span>
                    </button>
                </template>
            </div>
        </div>

        <div style="margin-bottom:1.25rem;">
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;opacity:.5;margin-bottom:.5rem;">
                {{ __('common.labels.nodes') }}</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.75rem;">
                <template x-for="n in visibleNodes" :key="n.cluster + '/' + n.name">
                    <div @click="toggleNode(n.name)"
                        style="cursor:pointer;border-radius:.6rem;padding:.85rem 1rem;transition:border-color .1s;border:1px solid;"
                        :style="nodeActive(n.name) ? 'border-color:#22c55e;background:rgba(34,197,94,.06);' :
                            'border-color:#27272a;background:transparent;'">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:.45rem;font-weight:600;">
                                <span style="width:.5rem;height:.5rem;border-radius:9999px;"
                                    :style="'background:' + (n.status === 'Online' ? '#22c55e' : '#ef4444')"></span>
                                <span x-text="n.name"></span>
                            </div>
                            <span style="font-size:.75rem;opacity:.55;"
                                x-text="n.count + ' ' + @js(__('clusters.overview.node_inst_count', ['count' => ''])).trim()"></span>
                        </div>
                        <div style="font-family:monospace;font-size:.8rem;opacity:.7;margin-top:.4rem;">
                            <span x-text="n.host"></span><span style="opacity:.45;"
                                x-text="n.port ? ':' + n.port : ''"></span>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.5rem;"
                            x-show="n.roles && n.roles.length">
                            <template x-for="role in n.roles" :key="role">
                                <span
                                    style="font-size:.65rem;padding:.1rem .4rem;border-radius:.3rem;background:rgba(255,255,255,.05);opacity:.7;"
                                    x-text="role"></span>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;">
            <input type="text" x-model="search" placeholder="{{ __('common.actions.search_instances') }}…"
                style="flex:1;padding:.55rem .9rem;border-radius:.5rem;border:1px solid #3f3f46;background:transparent;color:inherit;font-size:.9rem;">
            <span style="opacity:.5;font-size:.85rem;white-space:nowrap;"
                x-text="filtered.length + ' ' + @js(__('common.phrases.shown'))"></span>
            <button x-show="selectedClusters.length || selectedNodes.length || search" @click="clearAll()"
                style="opacity:.6;font-size:.85rem;cursor:pointer;background:none;border:none;color:inherit;text-decoration:underline;"
                x-text="@js(__('common.actions.clear'))"></button>
        </div>

        <div style="border:1px solid #27272a;border-radius:.75rem;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                <thead>
                    <tr style="text-align:left;background:rgba(255,255,255,.02);">
                        <template x-for="col in columns" :key="col.field">
                            <th @click="sortBy(col.field)"
                                style="padding:.7rem 1rem;font-weight:500;opacity:.6;cursor:pointer;user-select:none;white-space:nowrap;">
                                <span x-text="col.label"></span>
                                <span x-show="sortField === col.field" x-text="sortAsc ? ' ↑' : ' ↓'"
                                    style="opacity:.8;"></span>
                            </th>
                        </template>
                        <th style="padding:.7rem 1rem;font-weight:500;opacity:.6;">{{ __('common.labels.actions') }}
                        </th>
                    </tr>
                </thead>
                <template x-for="entry in displayRows" :key="entry.i.cluster + '/' + entry.i.name">
                    <tbody>
                        <tr style="border-top:1px solid #27272a;">
                            <td style="padding:.7rem 1rem;">
                                <span x-data="{ show: false }"
                                    style="display:inline-flex;flex-direction:column;align-items:flex-start;gap:.15rem;">
                                    <span style="display:inline-flex;align-items:center;gap:.4rem;font-weight:600;">
                                        <span x-text="entry.i.name"></span>
                                        <template x-if="entry.children.length">
                                            <button type="button" @click.stop="toggleGroup(entry.app)"
                                                :title="@js(__('instances.revisions.older_tip'))"
                                                style="cursor:pointer;border:1px solid #3f3f46;background:rgba(255,255,255,.04);color:inherit;opacity:.7;font-size:.68rem;font-weight:500;padding:.05rem .45rem;border-radius:9999px;display:inline-flex;align-items:center;gap:.25rem;line-height:1.4;">
                                                <span x-text="isExpanded(entry.app) ? '▾' : '▸'"
                                                    style="display:inline-block;transition:transform .2s ease;"></span>
                                                <span
                                                    x-text="entry.children.length + ' ' + @js(__('instances.revisions.older'))"></span>
                                            </button>
                                        </template>
                                        <template x-if="entry.i.needs_restart">
                                            <button type="button" @click.stop="show = !show"
                                                :title="entry.i.restart_reason"
                                                aria-label="{{ __('instances.restart.title') }}"
                                                style="cursor:pointer;border:none;background:none;padding:0;display:inline-flex;align-items:center;line-height:1;color:#f59e0b;">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                    fill="currentColor" style="width:.95rem;height:.95rem;">
                                                    <path fill-rule="evenodd"
                                                        d="M9.401 3.003c1.155-2 4.043-2 5.197 0l7.355 12.748c1.154 2-.29 4.5-2.599 4.5H4.645c-2.309 0-3.752-2.5-2.598-4.5L9.4 3.003zM12 8.25a.75.75 0 0 1 .75.75v3.75a.75.75 0 0 1-1.5 0V9a.75.75 0 0 1 .75-.75zm0 8.25a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5z"
                                                        clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </template>
                                    </span>
                                    <template x-if="entry.i.needs_restart && show">
                                        <span x-text="entry.i.restart_reason"
                                            style="display:block;max-width:26rem;white-space:normal;font-weight:400;font-size:.78rem;line-height:1.4;color:#f59e0b;opacity:.9;"></span>
                                    </template>
                                </span>
                            </td>
                            <td style="padding:.7rem 1rem;opacity:.7;" x-text="entry.i.cluster_label"></td>
                            <td style="padding:.7rem 1rem;opacity:.7;" x-text="entry.i.node"></td>
                            <td style="padding:.7rem 1rem;">
                                <span
                                    style="font-size:.75rem;padding:.15rem .5rem;border-radius:.35rem;border:1px solid #3f3f46;opacity:.8;"
                                    x-text="entry.i.type === 'virtual-machine' ? @js(__('instances.types.vm_short')) : @js(__('instances.types.container_short'))"></span>
                            </td>
                            <td style="padding:.7rem 1rem;">
                                <span style="display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;">
                                    <span style="width:.5rem;height:.5rem;border-radius:9999px;"
                                        :style="'background:' + (entry.i.status === 'Running' ? '#22c55e' : '#71717a')"></span>
                                    <span x-text="entry.i.status"></span>
                                </span>
                            </td>
                            <td style="padding:.7rem 1rem;font-family:monospace;opacity:.8;"
                                x-text="entry.i.ipv4 || '—'">
                            </td>
                            <td style="padding:.7rem 1rem;">
                                <div x-show="pending !== entry.i.cluster + '/' + entry.i.name"
                                    style="display:flex;gap:.4rem;">
                                    <button
                                        @click="$dispatch('open-instance-detail', { cluster: entry.i.cluster, name: entry.i.name })"
                                        :style="btn('#6366f1')" x-text="@js(__('common.labels.details'))"></button>
                                    <template x-if="!entry.i.retired">
                                        <span style="display:contents;">
                                            <button x-show="entry.i.status !== 'Running'" @click="act('start', entry.i)"
                                                :style="btn('#22c55e')" x-text="@js(__('common.actions.start'))"></button>
                                            <button x-show="entry.i.status === 'Running'" @click="act('restart', entry.i)"
                                                :style="btn('#a1a1aa')" x-text="@js(__('common.actions.restart'))"></button>
                                            <button x-show="entry.i.status === 'Running'" @click="act('stop', entry.i)"
                                                :style="btn('#ef4444')" x-text="@js(__('common.actions.stop'))"></button>
                                        </span>
                                    </template>
                                </div>
                                <span x-show="pending === entry.i.cluster + '/' + entry.i.name"
                                    style="opacity:.5;font-size:.8rem;" x-text="@js(__('common.status.working'))"></span>
                            </td>
                        </tr>
                        <template x-if="entry.children.length">
                            <tr>
                                <td :colspan="columns.length + 1" style="padding:0;border:0;">
                                    <div
                                        :style="'overflow:hidden;transition:max-height .28s ease;max-height:' + (isExpanded(entry.app) ? (entry.children.length * 2.6) + 'rem' : '0px')">
                                        <template x-for="c in entry.children"
                                            :key="c.cluster + '/' + c.name">
                                                <div
                                                    style="display:flex;align-items:center;gap:.75rem;padding:.5rem 1rem .5rem 2.6rem;border-top:1px solid #27272a;background:rgba(255,255,255,.02);font-size:.85rem;">
                                                    <span style="opacity:.4;">↳</span>
                                                    <span
                                                        style="font-weight:500;opacity:.7;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                                        x-text="c.name"></span>
                                                    <span style="opacity:.5;font-size:.8rem;white-space:nowrap;"
                                                        x-text="c.node"></span>
                                                    <span
                                                        style="display:inline-flex;align-items:center;gap:.35rem;opacity:.7;white-space:nowrap;">
                                                        <span style="width:.45rem;height:.45rem;border-radius:9999px;"
                                                            :style="'background:' + (c.status === 'Running' ? '#22c55e' : '#71717a')"></span>
                                                        <span x-text="c.status"></span>
                                                    </span>
                                                    <button
                                                        @click="$dispatch('open-instance-detail', { cluster: c.cluster, name: c.name })"
                                                        :style="btn('#6366f1')"
                                                        x-text="@js(__('common.labels.details'))"></button>
                                                </div>
                                            </template>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </template>
                <tbody x-show="displayRows.length === 0">
                    <tr>
                        <td colspan="7" style="padding:2rem;text-align:center;opacity:.5;">
                            {{ __('instances.create.image_no_matches') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function clusterView(clusters, members, instances) {
            return {
                clusters,
                members,
                instances,
                selectedClusters: [],
                selectedNodes: [],
                search: '',
                sortField: 'name',
                sortAsc: true,
                pending: null,
                expanded: {},
                columns: [{
                        field: 'name',
                        label: @js(__('common.labels.name'))
                    },
                    {
                        field: 'cluster_label',
                        label: @js(__('common.labels.cluster'))
                    },
                    {
                        field: 'node',
                        label: @js(__('common.labels.node'))
                    },
                    {
                        field: 'type',
                        label: @js(__('common.labels.type'))
                    },
                    {
                        field: 'status',
                        label: @js(__('common.labels.status'))
                    },
                    {
                        field: 'ipv4',
                        label: @js(__('common.labels.ipv4'))
                    },
                ],

                init() {
                    window.addEventListener('instance-changed', async () => {
                        const freshInstances = await this.$wire.get('instances');
                        if (Array.isArray(freshInstances)) this.instances = freshInstances;
                        const freshMembers = await this.$wire.get('members');
                        if (Array.isArray(freshMembers)) this.members = freshMembers;
                        const freshClusters = await this.$wire.get('clusters');
                        if (Array.isArray(freshClusters)) this.clusters = freshClusters;
                    });
                },

                clusterActive(k) {
                    return this.selectedClusters.length === 0 || this.selectedClusters.includes(k);
                },
                nodeActive(n) {
                    return this.selectedNodes.length === 0 || this.selectedNodes.includes(n);
                },

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

                clearAll() {
                    this.selectedClusters = [];
                    this.selectedNodes = [];
                    this.search = '';
                },

                sortBy(f) {
                    this.sortField === f ? (this.sortAsc = !this.sortAsc) : (this.sortField = f, this.sortAsc = true);
                },

                fuzzy(needle, hay) {
                    needle = (needle || '').toLowerCase();
                    hay = (hay || '').toLowerCase();
                    let i = 0;
                    for (const c of hay)
                        if (i < needle.length && c === needle[i]) i++;
                    return i === needle.length;
                },

                get visibleNodes() {
                    return this.members.filter(m => this.selectedClusters.length === 0 || this.selectedClusters
                        .includes(m.cluster));
                },

                chipStyle(active, color) {
                    return `padding:.35rem .8rem;border-radius:9999px;font-size:.85rem;font-weight:500;cursor:pointer;border:1px solid ${active ? color : '#3f3f46'};background:${active ? color + '1f' : 'transparent'};color:${active ? color : 'inherit'};`;
                },

                btn(color) {
                    return `font-size:.75rem;padding:.2rem .6rem;border-radius:.35rem;cursor:pointer;border:1px solid ${color}66;background:${color}14;color:${color};`;
                },

                async act(verb, i) {
                    if (!confirm(@js(__('common.actions.confirm')) + ' “' + i.name + '”?')) return;
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
                    const f = this.sortField,
                        dir = this.sortAsc ? 1 : -1;
                    return out.sort((a, b) => String(a[f] ?? '').localeCompare(String(b[f] ?? ''), undefined, {
                        numeric: true
                    }) * dir);
                },

                isExpanded(app) {
                    return !!this.expanded[app];
                },

                toggleGroup(app) {
                    this.expanded = { ...this.expanded, [app]: !this.expanded[app] };
                },

                // Fold retired revisions under their app's live revision. A live
                // row carries its retired siblings as `children`, ALWAYS rendered
                // (inside a collapsed, height-animated drawer) so expand/collapse
                // can transition rather than snap. Retired revisions whose live
                // anchor isn't in view — reaped live, or filtered out by search /
                // node — show flat so nothing is hidden. Everything else
                // (standalone, live, update-ready) stays a normal top-level row.
                get displayRows() {
                    const retiredByApp = {};
                    const tops = [];
                    for (const i of this.filtered) {
                        if (i.retired && i.app)(retiredByApp[i.app] ||= []).push(i);
                        else tops.push(i);
                    }

                    const out = [];
                    const grouped = new Set();
                    for (const t of tops) {
                        const kids = (t.is_live && t.app) ? (retiredByApp[t.app] || []) : [];
                        if (kids.length) grouped.add(t.app);
                        out.push({
                            i: t,
                            app: t.app,
                            children: kids
                        });
                    }
                    for (const app in retiredByApp)
                        if (!grouped.has(app))
                            for (const k of retiredByApp[app]) out.push({
                                i: k,
                                app,
                                children: []
                            });
                    return out;
                },
            };
        }
    </script>
</x-filament-panels::page>
