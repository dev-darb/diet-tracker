@props(['title', 'subtitle' => null, 'icon' => null])

{{-- Calm placeholder for screens that arrive in later milestones (BUILD_PLAN J0.3). --}}
<div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/60 px-6 py-16 text-center">
    <div class="mb-4 flex size-12 items-center justify-center rounded-2xl bg-white text-emerald-600 shadow-sm ring-1 ring-zinc-100">
        {{ $icon ?? '' }}
    </div>
    <h2 class="text-lg font-semibold text-zinc-900">{{ $title }}</h2>
    @if ($subtitle)
        <p class="mt-1.5 max-w-xs text-sm leading-relaxed text-zinc-500">{{ $subtitle }}</p>
    @endif
    <span class="mt-5 inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-500">
        <span class="size-1.5 rounded-full bg-emerald-500"></span>
        Coming soon
    </span>
</div>
