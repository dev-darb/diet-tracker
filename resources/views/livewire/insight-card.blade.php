<?php

use App\Models\AiInsight;
use App\Services\InsightService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * "Your focus this week" card (BUILD_PLAN §6 J7.2; brief §9.6). Embeddable via
 * <livewire:insight-card /> on Home and Health.
 *
 * THIN by design (brief §4.1): it holds no analytics or AI logic. It asks
 * InsightService for the cached-or-generated weekly insight, and delegates the
 * three actions — "Why this matters", "Show me what I could eat" (surfacing the
 * referenced pantry items) and "Dismiss" — straight back to the service. The
 * insight itself (including whether AI or the deterministic fallback produced it)
 * is decided entirely in the service layer.
 */
new class extends Component
{
    /** "Why this matters" expanded. */
    public bool $why = false;

    /** "Show me what I could eat" expanded. */
    public bool $eat = false;

    public function toggleWhy(): void
    {
        $this->why = ! $this->why;
    }

    public function toggleEat(): void
    {
        $this->eat = ! $this->eat;
    }

    /** Dismiss persists — the insight isn't shown again for this period (§9.6). */
    public function dismiss(InsightService $insights): void
    {
        $insight = $insights->currentInsight(Auth::user());

        if ($insight !== null) {
            $insights->dismiss($insight);
        }

        $this->why = false;
        $this->eat = false;
    }

    /**
     * A general, non-medical note on why the focus nutrient matters (brief §9.10,
     * R4). Deliberately plain and non-alarming.
     */
    private function whyMatters(?string $focusKey): string
    {
        return match ($focusKey) {
            'fibre' => 'Fibre supports digestion and helps you feel full for longer. Wholegrains, beans, fruit and veg are the everyday sources.',
            'protein' => 'Protein helps maintain muscle and keeps meals satisfying. Spreading it across the day tends to work better than one large hit.',
            'fruit_veg' => 'A range of fruit and vegetables brings fibre, vitamins and variety. Aiming for different colours across the week is an easy rule of thumb.',
            'saturated_fat' => 'Keeping saturated fat moderate is a common general-health steer. Swapping some for unsaturated sources is a gentle way to ease it down.',
            'salt' => 'Lower salt is a widely shared general guideline. A lot of it comes from packaged foods, so small swaps add up.',
            'food_variety' => 'Eating a wider range of foods tends to broaden the nutrients you get without any calorie counting.',
            default => 'These are general, non-medical pointers based on typical adult reference intakes.',
        };
    }

    public function with(InsightService $insights): array
    {
        /** @var AiInsight|null $insight */
        $insight = $insights->currentInsight(Auth::user());

        return [
            'insight' => $insight,
            'why_text' => $insight !== null ? $this->whyMatters($insight->focus_key) : null,
            'pantry_items' => ($insight !== null && $this->eat)
                ? $insights->referencedPantryItems($insight)
                : collect(),
        ];
    }
}; ?>

<div>
    @if ($insight)
        @php
            $priorityRing = match ($insight->priority) {
                'high' => 'ring-amber-500/30',
                'medium' => 'ring-emerald-500/25',
                default => 'ring-zinc-200',
            };
            $accent = match ($insight->priority) {
                'high' => 'bg-amber-500',
                'medium' => 'bg-emerald-500',
                default => 'bg-zinc-400',
            };
        @endphp

        <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm ring-1 ring-inset {{ $priorityRing }}">
            <div class="flex items-start gap-3 px-5 py-4">
                <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $accent }}"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Your focus this week</p>
                    <h3 class="mt-1 text-base font-semibold text-zinc-900">{{ $insight->title }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">{{ $insight->body }}</p>

                    {{-- Actions (brief §9.6). --}}
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="toggleWhy"
                                class="rounded-full border border-zinc-200 px-3 py-1 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50">
                            {{ $why ? 'Hide' : 'Why this matters' }}
                        </button>
                        @if (! empty($insight->pantry_item_ids))
                            <button type="button" wire:click="toggleEat"
                                    class="rounded-full border border-zinc-200 px-3 py-1 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50">
                                {{ $eat ? 'Hide items' : 'Show me what I could eat' }}
                            </button>
                        @endif
                        <button type="button" wire:click="dismiss"
                                class="rounded-full px-3 py-1 text-xs font-medium text-zinc-400 transition hover:text-zinc-600">
                            Dismiss
                        </button>
                    </div>

                    @if ($why)
                        <p class="mt-3 rounded-xl bg-zinc-50 px-3 py-2.5 text-xs leading-relaxed text-zinc-600">
                            {{ $why_text }}
                        </p>
                    @endif

                    @if ($eat && $pantry_items->isNotEmpty())
                        <ul class="mt-3 space-y-1.5">
                            @foreach ($pantry_items as $item)
                                <li class="flex items-center justify-between rounded-xl bg-zinc-50 px-3 py-2">
                                    <a href="{{ route('pantry.item', $item) }}"
                                       class="text-sm font-medium text-zinc-800 hover:text-emerald-700">
                                        {{ trim(($item->canonicalProduct->brand ? $item->canonicalProduct->brand.' ' : '').$item->canonicalProduct->name) }}
                                    </a>
                                    <span class="text-xs tabular-nums text-zinc-400">
                                        {{ rtrim(rtrim(number_format((float) $item->current_quantity, 3), '0'), '.') }} {{ $item->quantity_unit?->value }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <x-app.health-disclaimer class="mt-3" />
                </div>
            </div>
        </div>
    @endif
</div>
