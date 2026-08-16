@props(['href' => null, 'primary' => false])

{{-- A full-width stacked console key: the primary/secondary action pair at the
     foot of a flow step. Inline and compact keys keep their own markup. --}}
@php($classes = 'key keycap block w-full px-4 py-3.5 text-center '.($primary ? 'key-action' : 'text-ink-dim'))
@if ($href !== null)
    <a href="{{ $href }}" wire:navigate {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    {{-- `type` is read off the attribute bag rather than merged: a duplicate
         type attribute resolves to the FIRST one in HTML, so hard-coding
         "button" here would silently swallow every submit. --}}
    <button type="{{ $attributes->get('type', 'button') }}" {{ $attributes->except('type')->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
