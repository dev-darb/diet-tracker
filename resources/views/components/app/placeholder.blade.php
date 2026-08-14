@props(['title', 'subtitle' => null, 'status' => 'NO DATA', 'glyph' => '----', 'tone' => 'faint'])

{{-- An idle or fault instrument state: the module is powered and calibrated,
     there is simply nothing (or the wrong thing) on the wire. Never an apology
     (design brief). Tones map to the fixed signal palette. --}}
@php($glyphColor = match ($tone) {
    'high' => 'text-high',
    'low' => 'text-low',
    'info' => 'text-info',
    default => 'text-ink-faint',
})
<div {{ $attributes->merge(['class' => 'module px-6 py-12 text-center']) }}>
    <p class="data-xl {{ $glyphColor }}" aria-hidden="true">{{ $glyph }}</p>
    <p class="silkscreen mt-2">{{ $status }}</p>
    <h2 class="voice-item mt-5 text-ink">{{ $title }}</h2>
    @if ($subtitle)
        <p class="voice-caption mx-auto mt-1.5 max-w-xs text-ink-dim">{{ $subtitle }}</p>
    @endif
    {{ $slot }}
</div>
