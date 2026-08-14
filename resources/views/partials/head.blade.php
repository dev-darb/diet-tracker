<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="theme-color" content="#0a0b0c" />

<title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

{{-- Typefaces are self-hosted (public/fonts) — no font CDN. Preload the two
     voices every screen uses; DSEG7 loads lazily for the Home readout. --}}
<link rel="preload" href="{{ asset('fonts/Archivo-Var.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('fonts/FragmentMono-Regular.woff2') }}" as="font" type="font/woff2" crossorigin>

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Jabba is a single committed dark world: the appearance toggle is retired
     and the `dark` class is fixed on <html> (design brief, Aug 2026). --}}
