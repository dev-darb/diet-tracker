<?php

use App\AI\Contracts\RecipeSuggester;
use App\Models\PantryItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Component;

/**
 * AI chef — the Pantry's DEFAULT face: a proactive day plan (breakfast, lunch,
 * dinner, snack) grounded in the user's actual stock, shaped by their goal,
 * targets and dietary constraints (assembled by PrismRecipeSuggester).
 *
 * Proactive: suggestions load automatically via wire:init — instantly from the
 * per-user day cache, or generated on first visit. "Fresh ideas" bypasses the
 * cache. Keyless -> renders a quiet not-configured note (the pantry defaults to
 * the Stock view instead, see pantry.blade.php).
 *
 * Standardised recipe format (design: instrument console): each card carries a
 * silkscreen slot label, the dish, a structured INGREDIENTS list where in-stock
 * rows are lit (LED dot + IN STOCK) and everything else reads as shopping-list
 * honesty, optional UPGRADES worth buying, and a collapsible recipe. Approx
 * figures are advisory (~ EST.) and never logged.
 */
new class extends Component
{
    /** @var array<int, array<string, mixed>>|null */
    public ?array $suggestions = null;

    public ?string $wellness = null;

    public bool $failed = false;

    public bool $loaded = false;

    private function cacheKey(): string
    {
        return 'ai-chef.v2.'.Auth::id().'.'.now()->toDateString();
    }

    public function suggest(RecipeSuggester $chef, bool $fresh = false): void
    {
        if (! $chef->available()) {
            $this->loaded = true;

            return;
        }

        $this->failed = false;

        if ($fresh) {
            Cache::forget($this->cacheKey());
        }

        $cached = Cache::get($this->cacheKey());

        if ($cached !== null) {
            $this->suggestions = $cached['suggestions'];
            $this->wellness = $cached['wellness'] ?? null;
            $this->loaded = true;

            return;
        }

        $candidates = $this->candidates();

        if ($candidates === []) {
            $this->failed = true;
            $this->loaded = true;

            return;
        }

        $ideas = $chef->suggest(Auth::user(), $candidates);

        if ($ideas === null || ! $ideas->hasSuggestions()) {
            $this->failed = true;
            $this->loaded = true;

            return;
        }

        $this->suggestions = $ideas->suggestions;
        $this->wellness = $ideas->wellnessNote;
        Cache::put($this->cacheKey(), ['suggestions' => $this->suggestions, 'wellness' => $this->wellness], now()->endOfDay());
        $this->loaded = true;
    }

    public function freshIdeas(RecipeSuggester $chef): void
    {
        $this->suggest($chef, fresh: true);
    }

    /**
     * @return list<array{id: int, label: string, quantity: string}>
     */
    private function candidates(): array
    {
        return PantryItem::with('canonicalProduct')
            ->where('user_id', Auth::id())
            ->where('current_quantity', '>', 0)
            ->get()
            ->map(fn (PantryItem $i) => [
                'id' => $i->id,
                'label' => trim($i->canonicalProduct->brand.' '.$i->canonicalProduct->name),
                'quantity' => rtrim(rtrim(number_format((float) $i->current_quantity, 3, '.', ''), '0'), '.').' '.$i->quantity_unit->shortLabelFor((float) $i->current_quantity),
            ])
            ->values()
            ->all();
    }

    public function with(RecipeSuggester $chef): array
    {
        return ['chefAvailable' => $chef->available()];
    }
}; ?>

<div wire:init="suggest">
    @if (! $chefAvailable)
        <x-app.placeholder
            status="AI-- NOT CONFIGURED"
            title="The chef isn't switched on yet"
            subtitle="Meal suggestions need the AI gateway key. Your stock still works — switch to the Stock view above." />
    @else
        {{-- Booting / thinking state: honest machine-at-work, not a fake list. --}}
        <div wire:loading.delay wire:target="suggest, freshIdeas">
            <div class="module flex flex-col items-center px-6 py-12 text-center">
                <svg class="size-6 animate-spin text-action" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="silkscreen mt-4">CHEF THINKING</p>
                <p class="voice-caption mt-1 text-ink-dim">Planning today from your stock…</p>
            </div>
        </div>

        <div wire:loading.remove wire:target="suggest, freshIdeas" class="space-y-3">
            @if ($failed)
                <x-app.placeholder
                    status="NO PLAN"
                    title="Couldn't cook anything up"
                    subtitle="A few more items in stock gives the chef more to work with — scan your next shop, then try again." />
                <x-app.console-key wire:click="freshIdeas">Try again</x-app.console-key>
            @endif

            @if ($suggestions !== null)
                @foreach ($suggestions as $idea)
                    <section class="module px-5 pb-4 pt-4">
                        <div class="flex items-baseline justify-between">
                            <h2 class="silkscreen">{{ strtoupper($idea['slot']) }}</h2>
                            @php($figures = array_filter([
                                $idea['approx_calories'] !== null ? '~'.number_format($idea['approx_calories']).' KCAL' : null,
                                $idea['approx_protein'] !== null ? '~'.number_format($idea['approx_protein']).'G' : null,
                            ]))
                            @if ($figures !== [])
                                <span class="data-sm text-ink-faint">{{ implode(' · ', $figures) }} EST.</span>
                            @endif
                        </div>

                        <p class="voice-item mt-2 text-ink">{{ $idea['title'] }}</p>
                        @if ($idea['summary'] !== '')
                            <p class="voice-caption mt-0.5 text-ink-dim">{{ $idea['summary'] }}</p>
                        @endif

                        {{-- INGREDIENTS: in-stock rows are lit; the rest is shopping-list honesty. --}}
                        @if ($idea['ingredients'] !== [])
                            <p class="silkscreen mt-3.5">Ingredients</p>
                            <ul class="mt-1.5 divide-y divide-seam">
                                @foreach ($idea['ingredients'] as $ingredient)
                                    @php($inStock = $ingredient['pantry_item_id'] !== null)
                                    <li class="flex min-h-[36px] items-center gap-2.5 py-1.5">
                                        <span aria-hidden="true" class="size-1.5 shrink-0 rounded-full {{ $inStock ? 'bg-good' : 'bg-seam-strong' }}"></span>
                                        <span class="voice-caption min-w-0 flex-1 truncate {{ $inStock ? 'text-ink' : 'text-ink-dim' }}">{{ $ingredient['name'] }}</span>
                                        @if ($ingredient['amount'] !== '')
                                            <span class="data-sm shrink-0 text-ink-dim uppercase">{{ $ingredient['amount'] }}</span>
                                        @endif
                                        <span class="data-micro shrink-0 {{ $inStock ? 'text-good' : 'text-ink-faint' }} uppercase">{{ $inStock ? 'In stock' : 'To get' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($idea['upgrades'] !== [])
                            <p class="silkscreen mt-3">Make it better</p>
                            <ul class="mt-1 space-y-1">
                                @foreach ($idea['upgrades'] as $upgrade)
                                    <li class="voice-caption text-ink-dim">+ {{ $upgrade }}</li>
                                @endforeach
                            </ul>
                        @endif

                        <div x-data="{ open: false }" class="mt-3 border-t border-seam pt-2.5">
                            <button type="button" x-on:click="open = !open" class="keycap-sm flex w-full items-center justify-between text-ink-dim transition hover:text-ink">
                                <span>Recipe</span>
                                <span x-text="open ? '−' : '+'" class="data-sm"></span>
                            </button>
                            <ol x-show="open" x-cloak class="mt-2 list-decimal space-y-1.5 pl-5 text-sm text-ink-dim">
                                @foreach ($idea['steps'] as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ol>
                        </div>
                    </section>
                @endforeach

                @if ($wellness !== null)
                    <section class="well !rounded-md px-5 py-3.5">
                        <h2 class="silkscreen">Worth knowing</h2>
                        <p class="voice-caption mt-1.5 text-ink-dim">{{ $wellness }}</p>
                        <p class="data-micro mt-1.5 text-ink-faint uppercase">General guidance, not medical advice</p>
                    </section>
                @endif

                <x-app.console-key wire:click="freshIdeas" wire:loading.attr="disabled">Fresh ideas</x-app.console-key>
                <p class="text-center text-xs text-ink-faint">Cooked one? Log it via Eat → Log a meal → Home-cooked and your stock updates itself.</p>
            @endif
        </div>
    @endif
</div>
