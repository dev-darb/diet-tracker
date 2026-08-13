<x-layouts.app :title="__('Home')">
    <div class="space-y-6">
        <div>
            <p class="text-sm text-zinc-500">{{ now()->format('l, j F') }}</p>
            <h1 class="mt-0.5 text-2xl font-semibold tracking-tight text-zinc-900">
                Hi {{ str(auth()->user()->name)->before(' ') }}
            </h1>
        </div>

        {{-- Today snapshot placeholder (real data arrives in Milestone 6, brief §9.3). --}}
        <div class="rounded-2xl bg-zinc-900 px-5 py-6 text-white">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Today</p>
            <p class="mt-1 text-3xl font-semibold">—</p>
            <p class="mt-1 text-sm text-zinc-400">Your daily snapshot will appear here once you start logging food.</p>
        </div>

        {{-- Primary shortcut: scan --}}
        <a href="{{ route('scan') }}" wire:navigate
           class="flex items-center justify-between rounded-2xl bg-emerald-600 px-5 py-4 text-white shadow-sm transition hover:bg-emerald-700">
            <div>
                <p class="text-base font-semibold">Scan a product</p>
                <p class="text-sm text-emerald-100">Add what you bought to your pantry.</p>
            </div>
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
            </svg>
        </a>

        <div class="grid grid-cols-2 gap-3">
            <a href="{{ route('pantry') }}" wire:navigate class="rounded-2xl border border-zinc-100 bg-white px-4 py-4 shadow-sm transition hover:border-zinc-200">
                <p class="text-sm font-semibold text-zinc-900">Pantry</p>
                <p class="mt-0.5 text-xs text-zinc-500">What you have</p>
            </a>
            <a href="{{ route('health') }}" wire:navigate class="rounded-2xl border border-zinc-100 bg-white px-4 py-4 shadow-sm transition hover:border-zinc-200">
                <p class="text-sm font-semibold text-zinc-900">Health</p>
                <p class="mt-0.5 text-xs text-zinc-500">Your trends</p>
            </a>
        </div>
    </div>
</x-layouts.app>
