<x-layouts.app :title="__('Scan')">
    <div class="space-y-5">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">Scan</h1>
            <p class="mt-1 text-sm text-zinc-500">Point your camera at one packaged product.</p>
        </div>

        <x-app.placeholder
            title="Scanning arrives soon"
            subtitle="You'll be able to photograph a product, have it identified, confirm it, and add it to your pantry.">
            <x-slot:icon>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
                </svg>
            </x-slot:icon>
        </x-app.placeholder>
    </div>
</x-layouts.app>
