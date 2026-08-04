<div>
    {{-- Repositories: the git repos kixctl watches and deploys. Add a repo (SSH
         clone URL first-class), and a push (webhook) or a poll deploys it. Create
         / Edit / Delete are pure kixctl state; Deploy now reuses the poller path
         on the queue, so its progress shows on the Updates tab like any deploy. --}}
    <h3 style="font-weight:600; font-size:1rem; margin-bottom:.35rem;">{{ __('repositories.table.heading') }}</h3>
    <p style="opacity:.7; font-size:.85rem; margin-bottom:.75rem;">{{ __('repositories.table.description') }}</p>

    {{ $this->table }}
</div>
