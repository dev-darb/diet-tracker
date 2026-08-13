@php
    $item = function (string $route, string $label) {
        $active = request()->routeIs($route);

        return [
            'href' => route($route),
            'label' => $label,
            'active' => $active,
            'class' => $active ? 'text-emerald-600' : 'text-zinc-400 hover:text-zinc-600',
        ];
    };

    $home = $item('home', 'Home');
    $pantry = $item('pantry', 'Pantry');
    $eat = $item('eat', 'Eat');
    $health = $item('health', 'Health');
    $scanActive = request()->routeIs('scan');
@endphp

<nav class="fixed inset-x-0 bottom-0 z-30" aria-label="Primary">
    <div class="mx-auto max-w-md">
        <div class="relative flex items-end justify-around border-t border-zinc-100 bg-white/95 px-2 pb-[env(safe-area-inset-bottom)] pt-2 backdrop-blur">

            {{-- Home --}}
            <a href="{{ $home['href'] }}" wire:navigate @if($home['active']) aria-current="page" @endif
               class="flex w-16 flex-col items-center gap-1 py-1 text-[11px] font-medium transition {{ $home['class'] }}">
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
                </svg>
                {{ $home['label'] }}
            </a>

            {{-- Pantry --}}
            <a href="{{ $pantry['href'] }}" wire:navigate @if($pantry['active']) aria-current="page" @endif
               class="flex w-16 flex-col items-center gap-1 py-1 text-[11px] font-medium transition {{ $pantry['class'] }}">
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                </svg>
                {{ $pantry['label'] }}
            </a>

            {{-- Scan — visually primary, raised centre action (brief §5) --}}
            <div class="flex w-16 flex-col items-center">
                <a href="{{ route('scan') }}" wire:navigate aria-label="Scan a product" @if($scanActive) aria-current="page" @endif
                   class="-mt-8 flex size-16 items-center justify-center rounded-full bg-emerald-600 text-white shadow-lg shadow-emerald-600/30 ring-4 ring-white transition hover:bg-emerald-700 {{ $scanActive ? 'ring-emerald-100' : '' }}">
                    <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
                    </svg>
                </a>
                <span class="mt-0.5 text-[11px] font-semibold {{ $scanActive ? 'text-emerald-600' : 'text-zinc-500' }}">Scan</span>
            </div>

            {{-- Eat --}}
            <a href="{{ $eat['href'] }}" wire:navigate @if($eat['active']) aria-current="page" @endif
               class="flex w-16 flex-col items-center gap-1 py-1 text-[11px] font-medium transition {{ $eat['class'] }}">
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v6.75m0 0a2.25 2.25 0 002.25-2.25V3m-4.5 0v4.5A2.25 2.25 0 008.25 9.75m0 0V21m7.5-18v18m0-18c1.243 0 2.25 1.79 2.25 4v5.25c0 .414-.336.75-.75.75H15.75" />
                </svg>
                {{ $eat['label'] }}
            </a>

            {{-- Health --}}
            <a href="{{ $health['href'] }}" wire:navigate @if($health['active']) aria-current="page" @endif
               class="flex w-16 flex-col items-center gap-1 py-1 text-[11px] font-medium transition {{ $health['class'] }}">
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h12M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25l2.25-2.25 1.5 1.5L15 8.25" />
                </svg>
                {{ $health['label'] }}
            </a>
        </div>
    </div>
</nav>
