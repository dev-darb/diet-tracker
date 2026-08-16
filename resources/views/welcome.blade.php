<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    {{-- The front door: the machine at rest, before it knows whose kitchen it
         is. Same chassis, same seams, same orange key — so signing in feels
         like stepping into the room you were just looking at. --}}
    <body class="min-h-dvh bg-chassis text-ink antialiased">
        <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col px-4 py-8">
            <div class="flex items-center gap-2 px-1 py-2">
                <span class="size-2 rounded-full bg-action" aria-hidden="true"></span>
                {{-- The brand, not APP_NAME: the env var is infrastructure. Same
                     literal as the app shell so the door matches the room. --}}
                <span class="silkscreen !text-ink">foody</span>
            </div>

            <div class="flex flex-1 flex-col justify-center">
                <div class="module px-5 pb-5 pt-4">
                    <h2 class="silkscreen">What it does</h2>
                    <h1 class="voice-title mt-3 text-ink">
                        Know what you buy, what you eat, and how it adds up.
                    </h1>
                    <p class="voice-body mt-2 text-ink-dim">
                        Scan a shop into your pantry, log what you actually eat, and get one
                        honest read on the day — grounded in your own kitchen.
                    </p>

                    {{-- The loop, stated as the machine's three stations. --}}
                    <div class="-mx-5 mt-5 grid grid-cols-3 divide-x divide-seam border-t border-seam">
                        <div class="px-4 py-3">
                            <x-app.icon name="camera" class="size-4 text-ink-faint" />
                            <p class="voice-micro mt-1.5 text-ink-dim">Scan it</p>
                        </div>
                        <div class="px-4 py-3">
                            <x-app.icon name="fork" class="size-4 text-ink-faint" />
                            <p class="voice-micro mt-1.5 text-ink-dim">Eat it</p>
                        </div>
                        <div class="px-4 py-3">
                            <x-app.icon name="pulse" class="size-4 text-ink-faint" />
                            <p class="voice-micro mt-1.5 text-ink-dim">See the day</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-2">
                <x-app.console-key primary :href="route('register')">Create an account</x-app.console-key>
                <x-app.console-key :href="route('login')">Log in</x-app.console-key>
                <p class="voice-micro pt-2 text-center text-ink-faint">
                    General nutrition guidance, not medical advice.
                </p>
            </div>
        </div>
    </body>
</html>
