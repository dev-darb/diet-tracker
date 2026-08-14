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

        <div class="module px-5 pb-4 pt-4">
            <div class="flex items-center justify-between">
                <h2 class="silkscreen">Focus</h2>
                <span class="size-2 rounded-full bg-info" aria-hidden="true"></span>
            </div>

            <h3 class="voice-item mt-3 text-ink">{{ $insight->title }}</h3>
            <p class="voice-body mt-1.5 text-ink-dim">{{ $insight->body }}</p>

            {{-- Actions (brief §9.6). --}}
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="toggleWhy"
                        class="key keycap-sm hit px-3.5 py-2 text-ink-dim">
                    {{ $why ? 'Hide' : 'Why this matters' }}
                </button>
                @if (! empty($insight->pantry_item_ids))
                    <button type="button" wire:click="toggleEat"
                            class="key keycap-sm hit px-3.5 py-2 text-ink-dim">
                        {{ $eat ? 'Hide items' : 'What could I eat' }}
                    </button>
                @endif
                <button type="button" wire:click="dismiss"
                        class="keycap-sm hit px-3.5 py-2 text-ink-faint transition hover:text-ink-dim">
                    Dismiss for this week
                </button>
            </div>

            @if ($why)
                <p class="voice-caption mt-3 border-l border-info/60 bg-plate-well px-3 py-2.5 text-ink-dim">
                    {{ $why_text }}
                </p>
            @endif

            @if ($eat && $pantry_items->isNotEmpty())
                <ul class="mt-3 divide-y divide-seam border-t border-seam">
                    @foreach ($pantry_items as $item)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <a href="{{ route('pantry.item', $item) }}"
                               class="voice-caption min-w-0 truncate text-ink transition hover:text-info">
                                {{ trim(($item->canonicalProduct->brand ? $item->canonicalProduct->brand.' ' : '').$item->canonicalProduct->name) }}
                            </a>
                            <span class="data-sm shrink-0 text-ink-dim">
                                {{ rtrim(rtrim(number_format((float) $item->current_quantity, 3), '0'), '.') }} <span class="uppercase">{{ $item->quantity_unit?->value ?? '' }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

        </div>
    @endif
</div>
