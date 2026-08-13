<x-layouts.app :title="__('Health')">
    <div class="space-y-5">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">Health</h1>
            <p class="mt-1 text-sm text-zinc-500">Today, this week, and your trends.</p>
        </div>

        <x-app.placeholder
            title="No insights yet"
            subtitle="After a few days of logging, you'll see daily and weekly nutrition figures, component indicators, and one useful recommendation.">
            <x-slot:icon>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h12M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3M9 11.25l2.25-2.25 1.5 1.5L15 8.25" />
                </svg>
            </x-slot:icon>
        </x-app.placeholder>
    </div>
</x-layouts.app>
