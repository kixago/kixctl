<div>
    {{-- Pools: groups of apps that promote together (D28). Create / relabel /
         remove here; assign an app to a pool on the Repositories tab. Pure kixctl
         state — a pool is a database row with no cluster side effects. --}}
    <h3 style="font-weight:600; font-size:1rem; margin-bottom:.35rem;">{{ __('pools.table.heading') }}</h3>
    <p style="opacity:.7; font-size:.85rem; margin-bottom:.75rem;">{{ __('pools.table.description') }}</p>

    {{ $this->table }}
</div>
