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
 * InsightService for the cached-or-generated weekly insight, and delegates
 * dismissal (and its undo) straight back to the service.
 *
 * NEVER blocks the page ("the interface should never make the user wait for
 * the AI", founder, Aug 2026): the host page renders instantly and the insight
 * loads via wire:init — the first visit of a week generates in that follow-up
 * request while the rest of the screen is already usable. Disclosure toggles
 * are pure client state; only dismiss/undo touch the server.
 */
new class extends Component
{
    /** Set by wire:init — with() makes no service call until then. */
    public bool $loaded = false;

    /** Set after a dismiss so the strip can offer Undo (mis-taps shouldn't cost a week). */
    public ?int $dismissedId = null;

    public function load(): void
    {
        $this->loaded = true;
    }

    /** Dismiss persists — the insight isn't shown again for this period (§9.6). */
    public function dismiss(InsightService $insights): void
    {
        $insight = $insights->currentInsight(Auth::user());

        if ($insight !== null) {
            $insights->dismiss($insight);
            $this->dismissedId = $insight->id;
        }
    }

    public function undoDismiss(InsightService $insights): void
    {
        $insight = AiInsight::find($this->dismissedId);

        if ($insight !== null && $insight->user_id === Auth::id()) {
            $insights->undismiss($insight);
        }

        $this->dismissedId = null;
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
        $insight = $this->loaded ? $insights->currentInsight(Auth::user()) : null;

        return [
            'insight' => $insight,
            'why_text' => $insight !== null ? $this->whyMatters($insight->focus_key) : null,
            'pantry_items' => $insight !== null
                ? $insights->referencedPantryItems($insight)
                : collect(),
        ];
    }
}; ?>

<div wire:init="load">
    {{-- While the week's insight is read (or first generated), the page around
         this card is already live — this is the only thing still thinking. --}}
    <div wire:loading.delay wire:target="load">
        <div class="module flex items-center gap-3 px-5 py-4">
            <span class="size-2 shrink-0 animate-pulse rounded-full bg-info" aria-hidden="true"></span>
            <span class="silkscreen">Focus</span>
            <span class="voice-caption text-ink-dim">Reading your week…</span>
        </div>
    </div>

    <div wire:loading.remove wire:target="load">
    @if ($insight)

        <div class="module px-5 pb-4 pt-4" x-data="{ why: false, eat: false }">
            <div class="flex items-center justify-between">
                <h2 class="silkscreen">Focus</h2>
                <span class="size-2 rounded-full bg-info" aria-hidden="true"></span>
            </div>

            <h3 class="voice-item mt-3 text-ink">{{ $insight->title }}</h3>
            <p class="voice-body mt-1.5 text-ink-dim">{{ $insight->body }}</p>

            {{-- Actions (brief §9.6). Disclosure is client-side — instant. --}}
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button type="button" x-on:click="why = !why"
                        class="key keycap-sm hit px-3.5 py-2 text-ink-dim">
                    <span x-text="why ? 'Hide' : 'Why this matters'">Why this matters</span>
                </button>
                @if ($pantry_items->isNotEmpty())
                    <button type="button" x-on:click="eat = !eat"
                            class="key keycap-sm hit px-3.5 py-2 text-ink-dim">
                        <span x-text="eat ? 'Hide items' : 'What could I eat'">What could I eat</span>
                    </button>
                @endif
                <button type="button" wire:click="dismiss" wire:loading.attr="disabled"
                        class="keycap-sm hit px-3.5 py-2 text-ink-faint transition hover:text-ink-dim">
                    Dismiss for this week
                </button>
            </div>

            <p x-show="why" x-cloak class="voice-caption mt-3 border-l border-info/60 bg-plate-well px-3 py-2.5 text-ink-dim">
                {{ $why_text }}
            </p>

            @if ($pantry_items->isNotEmpty())
                <ul x-show="eat" x-cloak class="mt-3 divide-y divide-seam border-t border-seam">
                    @foreach ($pantry_items as $item)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <a href="{{ route('pantry.item', $item) }}" wire:navigate
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
    @elseif ($dismissedId !== null)
        {{-- The undo strip: dismissal is an automatic action, not a commitment. --}}
        <div class="module flex items-center justify-between gap-3 px-5 py-3">
            <span class="voice-caption text-ink-dim">Focus dismissed for this week.</span>
            <button type="button" wire:click="undoDismiss" wire:loading.attr="disabled"
                    class="keycap-sm hit shrink-0 text-ink transition hover:text-action">
                Undo
            </button>
        </div>
    @endif
    </div>
</div>
