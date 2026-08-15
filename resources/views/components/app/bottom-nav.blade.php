@php
    $item = function (string $route, string $label, string $icon) {
        return [
            'href' => route($route),
            'label' => $label,
            'icon' => $icon,
            'active' => request()->routeIs($route),
        ];
    };

    $keys = [
        $item('home', 'Home', 'house'),
        $item('pantry', 'Pantry', 'basket'),
        $item('eat', 'Eat', 'fork'),
        $item('health', 'Health', 'pulse'),
    ];
    $scanActive = request()->routeIs('scan');
@endphp

{{-- The control strip (redesigned Aug 2026, founder feedback): a raised
     machined plate, not a row of outlined boxes. Four engraved stations —
     glyph over micro-caption, lit when active with an orange indicator tick
     seated under the plate's edge — and the orange SCAN key punched THROUGH
     the edge on a chassis ring, floating clear of the seam instead of
     touching it. Press feedback is deliberately physical: stations sink and
     brighten; the SCAN key drops onto its ledge. --}}
<nav class="fixed inset-x-0 bottom-0 z-30" aria-label="Primary">
    <div class="mx-auto max-w-md border-t border-seam-strong bg-plate">
        <div class="relative grid grid-cols-5 items-stretch px-2 pb-[max(env(safe-area-inset-bottom),0.625rem)] pt-1.5">
            @foreach ([0, 1] as $i)
                <a href="{{ $keys[$i]['href'] }}" wire:navigate @if($keys[$i]['active']) aria-current="page" @endif
                   class="group station relative flex min-h-[52px] flex-col items-center justify-center gap-1 rounded-md {{ $keys[$i]['active'] ? 'text-ink' : 'text-ink-faint' }}">
                    @if ($keys[$i]['active'])
                        <span class="absolute -top-1.5 h-0.5 w-7 rounded-full bg-action" aria-hidden="true"></span>
                    @endif
                    <x-app.icon :name="$keys[$i]['icon']" class="size-5" />
                    <span class="station-caption">{{ $keys[$i]['label'] }}</span>
                </a>
            @endforeach

            {{-- SCAN — the machine's primary control: punched through the
                 plate edge on a chassis ring, floating clear of the seam. --}}
            <div class="relative">
                <a href="{{ route('scan') }}" wire:navigate aria-label="Scan a product" @if($scanActive) aria-current="page" @endif
                   class="scan-key flex size-[3.75rem] items-center justify-center rounded-2xl {{ $scanActive ? 'scan-key-lit' : '' }}">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2" />
                        <path d="M7 12h0.01M10.5 12h0.01M14 12h0.01M17 12h0.01" stroke-width="2.4" />
                    </svg>
                </a>
            </div>

            @foreach ([2, 3] as $i)
                <a href="{{ $keys[$i]['href'] }}" wire:navigate @if($keys[$i]['active']) aria-current="page" @endif
                   class="group station relative flex min-h-[52px] flex-col items-center justify-center gap-1 rounded-md {{ $keys[$i]['active'] ? 'text-ink' : 'text-ink-faint' }}">
                    @if ($keys[$i]['active'])
                        <span class="absolute -top-1.5 h-0.5 w-7 rounded-full bg-action" aria-hidden="true"></span>
                    @endif
                    <x-app.icon :name="$keys[$i]['icon']" class="size-5" />
                    <span class="station-caption">{{ $keys[$i]['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</nav>
