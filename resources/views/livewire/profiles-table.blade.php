<div>
    {{-- Profiles: the locked kix + any kixctl-created/registered profiles.
         Create / Register / Edit / Delete all route through ProfileManager, which
         is probe-proven. The locked kix row is guarded at the model layer and
         hides Edit/Delete here, exactly like kixbr0 on the Network tab. --}}
    <h3 style="font-weight:600; font-size:1rem; margin-bottom:.35rem;">{{ __('profiles.table.heading') }}</h3>
    <p style="opacity:.7; font-size:.85rem; margin-bottom:.75rem;">{{ __('profiles.empty.description') }}</p>

    {{ $this->table }}
</div>
