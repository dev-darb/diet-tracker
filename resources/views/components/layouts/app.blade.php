@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <style>[x-cloak]{display:none!important}</style>
    </head>
    <body class="min-h-dvh bg-chassis font-sans text-ink antialiased">
        <!--
        THESIS: A personal food intelligence console — every number an instrument
        readout, every fact carrying provenance; refuses the friendly wellness-card
        feed this category defaults to.
        OWN-WORLD: Matte near-black chassis, seam-separated module plates,
        silkscreen mono micro-labels; Archivo labels, Fragment Mono data, DSEG7 on
        one master readout; fixed signal palette — orange action/streak, green
        success, amber low, red high, cyan info. Rewards fire as hardware: color
        fields, LED sweeps, stamped checks, ticking counters.
        STORY: The owner glances their day, trusts the numbers, scans and logs,
        and feels each win land.
        FIRST VIEWPORT (Home): user-name status bar; dominant seven-segment TODAY
        kcal readout with tick scale; macro tiles; streak cells; indicator rows;
        control strip with raised orange SCAN key.
        FORM: brief-pinned TE dark console (user), comps home-comp-a + scan-success
        under .impeccable/mocks/, approved 2026-08-14 with sidecar amendments.
        FINISH: unreviewed and undocumented is unfinished; this build ends with the
        finish review, the verdict, and DESIGN.md.
        -->

        {{-- Mobile-first console shell (brief §5, §15; BUILD_PLAN J0.3). --}}
        <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col md:border-x md:border-seam">

            {{-- Top status bar: this is the user's console, so it carries their
                 name — the foody brand rides quietly beside it. --}}
            <header class="sticky top-0 z-20 border-b border-seam bg-chassis/95 backdrop-blur">
                <div class="flex items-center justify-between px-5 py-3">
                    <a href="{{ route('home') }}" class="flex items-baseline gap-2.5">
                        <span class="inline-block size-2 rounded-full bg-action" aria-hidden="true"></span>
                        <span class="data-md max-w-32 truncate tracking-[0.14em] text-ink uppercase">{{ str(auth()->user()?->name ?? 'Guest')->before(' ') }}</span>
                        <span class="silkscreen">foody</span>
                    </a>
                    <div class="flex items-center gap-3">
                        <span class="data-sm text-ink-dim uppercase">{{ now()->format('D j M') }}</span>
                        <a href="{{ route('profile') }}"
                           aria-label="Profile and settings"
                           class="key hit flex size-8 items-center justify-center text-xs font-medium text-ink-dim {{ request()->routeIs('profile') ? 'text-action' : '' }}">
                            {{ auth()->user()?->initials() ?: '?' }}
                        </a>
                    </div>
                </div>
            </header>

            {{-- Content --}}
            <main class="flex-1 px-4 pb-32 pt-4">
                {{ $slot }}
            </main>

            {{-- Link lamp: when the connection drops, say so before a change is lost. --}}
            <div x-data="{ online: navigator.onLine }"
                 x-on:online.window="online = true"
                 x-on:offline.window="online = false"
                 x-show="! online" x-cloak
                 role="status"
                 class="fixed inset-x-0 bottom-[6.5rem] z-40 mx-auto max-w-md px-4">
                <div class="flex items-center gap-2.5 rounded-md border border-high bg-plate px-4 py-2.5">
                    <span class="size-2 shrink-0 rounded-full bg-high" aria-hidden="true"></span>
                    <p class="data-sm text-ink uppercase">Link down — changes won't save until you're back online</p>
                </div>
            </div>

            {{-- Bottom navigation control strip (brief §5) --}}
            <x-app.bottom-nav />
        </div>

        @fluxScripts
    </body>
</html>
