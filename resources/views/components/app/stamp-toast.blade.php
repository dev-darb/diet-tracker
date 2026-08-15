@props(['show', 'tone' => 'good'])

{{-- The reward stamp (green, checked) for genuine wins; the neutral plate for
     quiet saves. `show` is an Alpine expression evaluated in the parent scope. --}}
<div x-show="{{ $show }}" x-cloak role="status" class="fixed inset-x-0 bottom-28 z-40 mx-auto max-w-md px-5">
    @if ($tone === 'good')
        <div class="stamp-in flex items-center justify-center gap-2.5 rounded-md bg-good px-4 py-3 text-black">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5.5 5.5L20 6.5" /></svg>
            <span class="keycap">{{ $slot }}</span>
        </div>
    @else
        {{-- Chassis-dark so the toast reads as a solid plate over any list. --}}
        <div class="stamp-in flex items-center justify-center gap-2.5 rounded-md border border-seam-strong bg-chassis px-4 py-3 text-ink">
            <span class="keycap">{{ $slot }}</span>
        </div>
    @endif
</div>
