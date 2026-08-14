@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-plate-well text-ink antialiased">
        <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col bg-plate shadow-sm ring-1 ring-seam">
            <main class="flex flex-1 flex-col px-6 py-8">
                {{ $slot }}
            </main>
        </div>
        @fluxScripts
    </body>
</html>
