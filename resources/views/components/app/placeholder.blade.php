@props(['title', 'subtitle' => null, 'icon' => null, 'status' => 'NO DATA'])

{{-- An idle instrument state: the module is powered and calibrated, there is
     simply nothing on the wire yet. Never an apology (design brief). --}}
<div class="module px-6 py-12 text-center">
    <p class="data text-2xl text-ink-faint" aria-hidden="true">----</p>
    <p class="silkscreen mt-2">{{ $status }}</p>
    <h2 class="mt-5 text-base font-medium text-ink">{{ $title }}</h2>
    @if ($subtitle)
        <p class="mx-auto mt-1.5 max-w-xs text-sm leading-relaxed text-ink-dim">{{ $subtitle }}</p>
    @endif
</div>
