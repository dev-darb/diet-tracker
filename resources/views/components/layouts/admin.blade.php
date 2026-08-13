@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-zinc-50 text-zinc-900 antialiased">
        {{-- Admin console shell (BUILD_PLAN §11). Desktop-oriented, deliberately
             plain — this is an internal tool, not the calm consumer surface. --}}
        <div class="mx-auto flex min-h-dvh w-full max-w-4xl flex-col px-4">

            <header class="sticky top-0 z-20 -mx-4 flex items-center justify-between border-b border-zinc-200 bg-white/90 px-4 py-3.5 backdrop-blur">
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.products.index') }}" wire:navigate class="flex items-center gap-2">
                        <span class="flex size-7 items-center justify-center rounded-lg bg-zinc-900 text-sm font-semibold text-white">A</span>
                        <span class="text-base font-semibold tracking-tight text-zinc-900">Admin</span>
                    </a>
                    <nav class="ml-3 flex items-center gap-1 text-sm">
                        <a href="{{ route('admin.products.index') }}" wire:navigate
                           class="rounded-lg px-3 py-1.5 font-medium transition {{ request()->routeIs('admin.products.*') ? 'bg-zinc-100 text-zinc-900' : 'text-zinc-500 hover:text-zinc-800' }}">
                            Products
                        </a>
                    </nav>
                </div>

                <a href="{{ route('home') }}" wire:navigate class="text-sm font-medium text-emerald-600 hover:text-emerald-700">
                    Back to app
                </a>
            </header>

            <main class="flex-1 py-6">
                {{ $slot }}
            </main>
        </div>

        @fluxScripts
    </body>
</html>
