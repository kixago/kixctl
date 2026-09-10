<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'kixctl — setup' }}</title>

    {{-- The same Vite/Tailwind/Instrument-Sans bundle the panel uses, so the
         pre-auth wizard looks like the product and not a stock Laravel page. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
    {{ $slot }}
    @livewireScripts
</body>
</html>
