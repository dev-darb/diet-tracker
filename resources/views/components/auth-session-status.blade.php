@props([
    'status',
])

@if ($status)
    {{-- A machine notice, in the confirm-green signal on a small area. --}}
    <p {{ $attributes->merge(['class' => 'voice-caption border-l border-good bg-plate-well px-3 py-2 text-ink-dim']) }} role="status">
        {{ $status }}
    </p>
@endif
