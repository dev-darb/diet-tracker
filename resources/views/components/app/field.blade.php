@props([
    'label',
    'name',
    'type' => 'text',
    'model' => null,
    'hint' => null,
])

{{-- One field anatomy for every form in the console: silkscreen label, seated
     input-well, error in the high signal beneath. Views never hand-roll a
     field — a form that invents its own anatomy is the card-soup tell. --}}
@php($errorKey = $model ?? $name)

<div>
    <div class="flex items-baseline justify-between gap-2">
        <label class="silkscreen" for="{{ $name }}">{{ $label }}</label>
        @if ($hint !== null)
            <span class="voice-micro text-ink-faint">{{ $hint }}</span>
        @endif
    </div>
    <input id="{{ $name }}"
           type="{{ $type }}"
           name="{{ $name }}"
           @if ($model !== null) wire:model="{{ $model }}" @endif
           {{ $attributes->merge(['class' => 'input-well mt-1.5 w-full']) }}>
    @error($errorKey)
        <p class="mt-1 text-xs text-high">{{ $message }}</p>
    @enderror
</div>
