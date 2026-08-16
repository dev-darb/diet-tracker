<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    {{-- The front door is the same machine, powered on before it knows who
         you are: chassis ground, one plate, and the FOODY nameplate — the one
         place the brand speaks (inside the app the console is the user's). --}}
    <body class="min-h-dvh bg-chassis text-ink antialiased">
        <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col px-4 py-8">
            <a href="{{ route('welcome') }}" class="flex items-center justify-center gap-2 py-2">
                <span class="size-2 rounded-full bg-action" aria-hidden="true"></span>
                {{-- The brand, not APP_NAME: the env var is infrastructure. Same
                     literal as the app shell so the door matches the room. --}}
                <span class="silkscreen !text-ink">foody</span>
            </a>

            <div class="flex flex-1 flex-col justify-center">
                <div class="module px-5 pb-5 pt-4">
                    {{ $slot }}
                </div>
            </div>

            <p class="voice-micro pt-4 text-center text-ink-faint">
                General nutrition guidance, not medical advice.
            </p>
        </div>
    </body>
</html>
