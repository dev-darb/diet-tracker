@php
    $item = function (string $route, string $label) {
        $active = request()->routeIs($route);

        return [
            'href' => route($route),
            'label' => $label,
            'active' => $active,
        ];
    };

    $keys = [
        $item('home', 'Home'),
        $item('pantry', 'Pantry'),
        $item('eat', 'Eat'),
        $item('health', 'Health'),
    ];
    $scanActive = request()->routeIs('scan');
@endphp

{{-- The console's control strip: five physical keys on one seam-lined plate,
     the orange SCAN key raised and dominant (brief §5, design brief). --}}
<nav class="fixed inset-x-0 bottom-0 z-30" aria-label="Primary">
    <div class="mx-auto max-w-md border-t border-seam bg-chassis/95 backdrop-blur">
        <div class="grid grid-cols-5 gap-2 px-3 pb-[max(env(safe-area-inset-bottom),0.75rem)] pt-3">
            @foreach ([0, 1] as $i)
                <a href="{{ $keys[$i]['href'] }}" @if($keys[$i]['active']) aria-current="page" @endif
                   class="key flex h-14 items-center justify-center">
                    <span class="silkscreen {{ $keys[$i]['active'] ? '!text-ink' : '' }}">{{ $keys[$i]['label'] }}</span>
                </a>
            @endforeach

            {{-- SCAN — the machine's primary control. --}}
            <a href="{{ route('scan') }}" aria-label="Scan a product" @if($scanActive) aria-current="page" @endif
               class="key key-action -mt-3 flex h-[4.25rem] flex-col items-center justify-center gap-1">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2" />
                    <path d="M7 12h0.01M10.5 12h0.01M14 12h0.01M17 12h0.01" stroke-width="2.4" />
                </svg>
                <span class="font-mono text-[11px] font-medium tracking-[0.14em] uppercase">Scan</span>
            </a>

            @foreach ([2, 3] as $i)
                <a href="{{ $keys[$i]['href'] }}" @if($keys[$i]['active']) aria-current="page" @endif
                   class="key flex h-14 items-center justify-center">
                    <span class="silkscreen {{ $keys[$i]['active'] ? '!text-ink' : '' }}">{{ $keys[$i]['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</nav>
