<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ config('app.name') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-dvh bg-plate-well text-ink antialiased">
        <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-between bg-plate px-6 py-10 shadow-sm ring-1 ring-seam">
            <div class="flex flex-1 flex-col justify-center">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-600 text-lg font-semibold text-white">P</span>
                <h1 class="mt-6 text-3xl font-semibold leading-tight tracking-tight text-ink">
                    Know what you buy, eat, and how it adds up.
                </h1>
                <p class="mt-3 text-base leading-relaxed text-ink-dim">
                    Scan your groceries, keep track of what's in your pantry, log what you eat,
                    and get calm, useful guidance about your diet.
                </p>
            </div>

            <div class="space-y-3">
                <a href="{{ route('register') }}"
                   class="block w-full rounded-xl bg-emerald-600 px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-emerald-700">
                    Create an account
                </a>
                <a href="{{ route('login') }}"
                   class="block w-full rounded-xl border border-seam bg-plate px-4 py-3 text-center text-sm font-semibold text-ink-dim transition hover:bg-plate-well">
                    Log in
                </a>
                <p class="pt-2 text-center text-xs text-ink-faint">General nutrition guidance, not medical advice.</p>
            </div>
        </div>
    </body>
</html>
