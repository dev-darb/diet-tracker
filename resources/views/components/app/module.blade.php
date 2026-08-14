@props(['label' => null, 'meta' => null, 'padding' => 'px-5 pb-5 pt-4'])

{{-- A labeled instrument module: plate + silkscreen label, optional right-side
     meta readout. Faceplate clusters with bespoke internals stay hand-built;
     this carries the ordinary labeled-plate case. --}}
<section {{ $attributes->merge(['class' => 'module '.$padding]) }}>
    @if ($label !== null || $meta !== null)
        <div class="flex items-baseline justify-between">
            @if ($label !== null)
                <h2 class="silkscreen">{{ $label }}</h2>
            @endif
            @if ($meta !== null)
                {{ $meta }}
            @endif
        </div>
    @endif
    {{ $slot }}
</section>
