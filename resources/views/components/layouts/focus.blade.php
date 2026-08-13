@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-zinc-50 text-zinc-900 antialiased">
        <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col bg-white shadow-sm ring-1 ring-zinc-100">
            <main class="flex flex-1 flex-col px-6 py-8">
                {{ $slot }}
            </main>
        </div>
        @fluxScripts
    </body>
</html>
