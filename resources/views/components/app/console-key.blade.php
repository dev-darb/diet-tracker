@props(['href' => null, 'primary' => false])

{{-- A full-width stacked console key: the primary/secondary action pair at the
     foot of a flow step. Inline and compact keys keep their own markup. --}}
@php($classes = 'key keycap block w-full px-4 py-3.5 text-center '.($primary ? 'key-action' : 'text-ink-dim'))
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="button" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
