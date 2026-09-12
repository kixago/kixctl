@php $labels = ['Domain', 'Admin', 'Incus']; @endphp

<div class="flex min-h-screen flex-col items-center justify-center px-6 py-12">
    <div class="mb-8 text-center">
        <div class="text-3xl font-bold tracking-tight">kixctl</div>
        <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
            Let's get your appliance set up — three quick steps and you're running.
        </p>
    </div>

    <div class="w-full max-w-lg rounded-2xl border border-neutral-200 bg-white p-8 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
        <nav class="mb-8 flex items-center justify-center">
            @foreach ($labels as $i => $label)
                @php $n = $i + 1; @endphp
                <div class="flex flex-col items-center">
                    <span @class([
                        'flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold transition-colors',
                        'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900' => $n === $step,
                        'bg-emerald-600 text-white' => $n < $step,
                        'bg-neutral-100 text-neutral-400 dark:bg-neutral-800' => $n > $step,
                    ])>
                        @if ($n < $step) &check; @else {{ $n }} @endif
                    </span>
                    <span @class([
                        'mt-1.5 text-xs',
                        'font-medium text-neutral-900 dark:text-neutral-100' => $n === $step,
                        'text-neutral-400' => $n !== $step,
                    ])>{{ $label }}</span>
                </div>
                @if (! $loop->last)
                    <span @class([
                        'mx-3 mb-5 h-px w-10',
                        'bg-emerald-600' => $n < $step,
                        'bg-neutral-200 dark:bg-neutral-800' => $n >= $step,
                    ])></span>
                @endif
            @endforeach
        </nav>

        @if ($step === 1)
            <h2 class="text-lg font-semibold">Where should kixctl live?</h2>
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                The address you'll use to reach the panel and the apps you deploy. A public
                domain or an internal one both work — kixctl handles the certificates either way.
            </p>
            <div class="mt-6">
                <label for="domain" class="block text-sm font-medium">Domain</label>
                <input id="domain" type="text" wire:model="domain" wire:keydown.enter="next" autofocus placeholder="kix.example.com"
                    class="mt-1.5 block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-neutral-900 focus:outline-none focus:ring-1 focus:ring-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white dark:focus:ring-white">
                <p class="mt-2 text-xs text-neutral-400">For example <span class="font-medium">kix.example.com</span> for a public setup, or <span class="font-medium">kix.home.arpa</span> on your home network.</p>
                @error('domain') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

        @elseif ($step === 2)
            <h2 class="text-lg font-semibold">Create your admin account</h2>
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                This is the login you'll use to manage kixctl. It replaces the temporary
                default account — pick a password you'll keep, at least 12 characters.
            </p>
            <div class="mt-6 space-y-4">
                <div>
                    <label for="adminEmail" class="block text-sm font-medium">Email</label>
                    <input id="adminEmail" type="email" wire:model="adminEmail" wire:keydown.enter="next"
                        class="mt-1.5 block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-neutral-900 focus:outline-none focus:ring-1 focus:ring-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white dark:focus:ring-white">
                    @error('adminEmail') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="adminPassword" class="block text-sm font-medium">Password</label>
                    <input id="adminPassword" type="password" wire:model="adminPassword" wire:keydown.enter="next" autocomplete="new-password"
                        class="mt-1.5 block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-neutral-900 focus:outline-none focus:ring-1 focus:ring-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white dark:focus:ring-white">
                    @error('adminPassword') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="adminPasswordConfirmation" class="block text-sm font-medium">Confirm password</label>
                    <input id="adminPasswordConfirmation" type="password" wire:model="adminPasswordConfirmation" wire:keydown.enter="next" autocomplete="new-password"
                        class="mt-1.5 block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-neutral-900 focus:outline-none focus:ring-1 focus:ring-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white dark:focus:ring-white">
                    @error('adminPasswordConfirmation') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

        @elseif ($step === 3)
            <h2 class="text-lg font-semibold">Connect Incus</h2>
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                Incus is the engine that runs your apps. Use the one built into this appliance,
                or connect a cluster you already run.
            </p>

            {{-- Choice --}}
            <div class="mt-6 space-y-3">
                <label @class([
                    'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors',
                    'border-neutral-900 ring-1 ring-neutral-900 dark:border-white dark:ring-white' => $incusMode === 'fresh',
                    'border-neutral-200 dark:border-neutral-800' => $incusMode !== 'fresh',
                ])>
                    <input type="radio" wire:model.live="incusMode" value="fresh" class="mt-1 accent-neutral-900 dark:accent-white">
                    <span>
                        <span class="block text-sm font-medium">Use this appliance's Incus</span>
                        <span class="mt-0.5 block text-sm text-neutral-500 dark:text-neutral-400">Start fresh — kixctl becomes the hypervisor on this machine. Nothing to configure.</span>
                    </span>
                </label>

                <label @class([
                    'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors',
                    'border-neutral-900 ring-1 ring-neutral-900 dark:border-white dark:ring-white' => $incusMode === 'bolt',
                    'border-neutral-200 dark:border-neutral-800' => $incusMode !== 'bolt',
                ])>
                    <input type="radio" wire:model.live="incusMode" value="bolt" class="mt-1 accent-neutral-900 dark:accent-white">
                    <span>
                        <span class="block text-sm font-medium">Connect an existing cluster</span>
                        <span class="mt-0.5 block text-sm text-neutral-500 dark:text-neutral-400">Already running Incus? Authorize kixctl with a one-time token.</span>
                    </span>
                </label>
            </div>

            {{-- Bolt-on fields --}}
            @if ($incusMode === 'bolt')
                <div class="mt-6 space-y-5 border-t border-neutral-200 pt-6 dark:border-neutral-800">
                    <div>
                        <label for="boltProject" class="block text-sm font-medium">Project</label>
                        <input id="boltProject" type="text" wire:model.live.debounce.300ms="boltProject" wire:keydown.enter="finish"
                            class="mt-1.5 block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-neutral-900 focus:outline-none focus:ring-1 focus:ring-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white dark:focus:ring-white">
                        <p class="mt-2 text-xs text-neutral-400">The Incus project kixctl is scoped to. Leave as <span class="font-medium">default</span> unless your apps live in their own project.</p>
                        @error('boltProject') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <span class="block text-sm font-medium">1. Run this on your cluster</span>
                        <p class="mt-1 text-xs text-neutral-400">Incus requires you to authorize kixctl — it can't add itself.</p>
                        <div x-data="{ copied: false }" class="mt-2 flex items-stretch gap-2">
                            <code x-ref="cmd" class="flex-1 overflow-x-auto rounded-lg bg-neutral-100 px-3 py-2 text-xs text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200">{{ $this->trustCommand }}</code>
                            <button type="button"
                                x-on:click="navigator.clipboard.writeText($refs.cmd.innerText); copied = true; setTimeout(() => copied = false, 1500)"
                                class="flex-none rounded-lg border border-neutral-300 px-3 text-xs font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                                <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label for="boltToken" class="block text-sm font-medium">2. Paste the token it prints</label>
                        <textarea id="boltToken" rows="3" wire:model.blur="boltToken"
                            class="mt-1.5 block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-neutral-900 focus:outline-none focus:ring-1 focus:ring-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white dark:focus:ring-white"></textarea>
                        @error('boltToken') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="boltEndpoint" class="block text-sm font-medium">Cluster address</label>
                        <input id="boltEndpoint" type="text" wire:model="boltEndpoint" wire:keydown.enter="finish" placeholder="https://192.168.1.10:8443"
                            class="mt-1.5 block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-neutral-900 focus:outline-none focus:ring-1 focus:ring-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white dark:focus:ring-white">
                        <p class="mt-2 text-xs text-neutral-400">Filled in from the token. Only change it if your cluster is reachable at a different address.</p>
                        @error('boltEndpoint') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

        @endif

        <div class="mt-8 flex items-center justify-between">
            @if ($step > 1)
                <button type="button" wire:click="back" class="text-sm font-medium text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">&larr; Back</button>
            @else
                <span></span>
            @endif

            @if ($step < $lastStep)
                <button type="button" wire:click="next" class="rounded-lg bg-neutral-900 px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200">Continue</button>
            @else
                <button type="button" wire:click="finish" wire:loading.attr="disabled"
                    class="rounded-lg bg-neutral-900 px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-neutral-700 disabled:opacity-60 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200">
                    <span wire:loading.remove wire:target="finish">Finish setup</span>
                    <span wire:loading wire:target="finish">Setting up…</span>
                </button>
            @endif
        </div>
    </div>

    <p class="mt-6 text-xs text-neutral-400">You can change any of this later in Settings.</p>
</div>
