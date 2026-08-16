@props([
    'title',
    'description' => null,
])

{{-- The plate's own label + the spoken line under it. Sentence case: this is
     the product speaking, not an engraving. --}}
<div class="flex w-full flex-col gap-1">
    <h1 class="voice-item text-ink">{{ $title }}</h1>
    @if ($description !== null)
        <p class="voice-caption text-ink-dim">{{ $description }}</p>
    @endif
</div>
