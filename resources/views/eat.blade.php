<x-layouts.app :title="__('Eat')">
    <div class="space-y-5">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">Eat</h1>
            <p class="mt-1 text-sm text-zinc-500">Log what you actually ate.</p>
        </div>

        <x-app.placeholder
            title="Nothing logged yet"
            subtitle="Once you have items in your pantry, you'll consume them here with a single tap or build a meal from several ingredients.">
            <x-slot:icon>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v6.75m0 0a2.25 2.25 0 002.25-2.25V3m-4.5 0v4.5A2.25 2.25 0 008.25 9.75m0 0V21m7.5-18v18m0-18c1.243 0 2.25 1.79 2.25 4v5.25c0 .414-.336.75-.75.75H15.75" />
                </svg>
            </x-slot:icon>
        </x-app.placeholder>
    </div>
</x-layouts.app>
