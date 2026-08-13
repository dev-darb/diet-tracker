<x-layouts.app :title="__('Pantry')">
    <div class="space-y-5">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">Pantry</h1>
            <p class="mt-1 text-sm text-zinc-500">What food you currently have.</p>
        </div>

        <x-app.placeholder
            title="Your pantry is empty"
            subtitle="Scan a product to start tracking what you own. Items you add will show here with how much is left.">
            <x-slot:icon>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                </svg>
            </x-slot:icon>
        </x-app.placeholder>
    </div>
</x-layouts.app>
