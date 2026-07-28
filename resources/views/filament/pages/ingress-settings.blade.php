<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="display:flex; gap:.75rem; margin-top:1.25rem;">
            {{ $this->saveAction }}
            {{ $this->defaultsAction }}
        </div>
    </form>

    <x-filament::section>
        <x-slot name="heading">{{ __('ingress.status.heading') }}</x-slot>

        <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.5rem;">
            <span style="width:.6rem;height:.6rem;border-radius:9999px;display:inline-block;background:{{ ($status['ready'] ?? false) ? '#22c55e' : '#f59e0b' }};"></span>
            <span>{{ $status['summary'] ?? '' }}</span>
        </div>

        @if (! empty($status['detail']))
            <dl style="font-size:.85rem; opacity:.85;">
                @foreach ($status['detail'] as $k => $v)
                    <div style="display:flex; gap:.5rem; padding:.15rem 0;">
                        <dt style="min-width:11rem; opacity:.6;">{{ $k }}</dt>
                        <dd style="font-family:ui-monospace,monospace;">{{ $v }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        <div wire:poll.15s="refreshStatus" style="display:none;"></div>
    </x-filament::section>
</x-filament-panels::page>
