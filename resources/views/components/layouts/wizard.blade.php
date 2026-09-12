<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'kixctl — setup' }}</title>
    <style>[x-cloak]{display:none !important;}</style>

    {{-- Same Vite/Tailwind/Instrument-Sans bundle the panel uses, so the pre-auth
         wizard looks like the product and not a stock Laravel page. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-neutral-50 font-sans text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
    {{ $slot }}

    {{-- Toast. Livewire's finish() dispatches `notify` {type, message, redirect?};
         success shows green then navigates to the panel, failure shows red and stays. --}}
    <div x-data="{ show: false, type: 'success', message: '' }"
         x-on:notify.window="
            type = $event.detail.type; message = $event.detail.message; show = true;
            if ($event.detail.redirect) { setTimeout(() => window.location.href = $event.detail.redirect, 1700); }
            else { setTimeout(() => show = false, 7000); }
         "
         x-cloak
         class="pointer-events-none fixed inset-x-0 bottom-6 z-50 flex justify-center px-6">
        <div x-show="show" x-transition.opacity.duration.200ms
             :class="type === 'success' ? 'bg-emerald-600' : 'bg-red-600'"
             class="max-w-md rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg">
            <span x-text="message"></span>
        </div>
    </div>

    @livewireScripts
</body>
</html>
