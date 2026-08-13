@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-zinc-50 text-zinc-900 antialiased">
        {{-- Mobile-first app shell (brief §5, §15; BUILD_PLAN J0.3). --}}
        <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col bg-white shadow-sm ring-1 ring-zinc-100">

            {{-- Top bar --}}
            <header class="sticky top-0 z-20 flex items-center justify-between border-b border-zinc-100 bg-white/90 px-5 py-3.5 backdrop-blur">
                <a href="{{ route('home') }}" class="flex items-center gap-2" wire:navigate>
                    <span class="flex size-7 items-center justify-center rounded-lg bg-emerald-600 text-sm font-semibold text-white">P</span>
                    <span class="text-base font-semibold tracking-tight text-zinc-900">{{ $title ?? 'Pantry' }}</span>
                </a>

                <a href="{{ route('profile') }}" wire:navigate
                   aria-label="Profile and settings"
                   class="flex size-9 items-center justify-center rounded-full bg-zinc-100 text-sm font-medium text-zinc-700 transition hover:bg-zinc-200 {{ request()->routeIs('profile') ? 'ring-2 ring-emerald-500' : '' }}">
                    {{ auth()->user()?->initials() ?: '?' }}
                </a>
            </header>

            {{-- Content --}}
            <main class="flex-1 px-5 pb-28 pt-5">
                {{ $slot }}
            </main>

            {{-- Bottom navigation (brief §5) --}}
            <x-app.bottom-nav />
        </div>

        @fluxScripts
    </body>
</html>
