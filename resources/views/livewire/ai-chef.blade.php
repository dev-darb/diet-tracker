<?php

use App\AI\Contracts\RecipeSuggester;
use App\Models\PantryItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Component;

/**
 * AI chef (Pantry): breakfast / lunch / dinner ideas with short recipes,
 * grounded in the user's ACTUAL stock and shaped by their goal, targets and
 * dietary constraints (all assembled by PrismRecipeSuggester).
 *
 * Results are cached per user per day — revisiting the Pantry shows today's
 * ideas instantly; "Fresh ideas" bypasses the cache. Keyless -> the module
 * renders nothing at all (same grace rule as every AI feature). Approx figures
 * are advisory (~) and never logged; cooking one of these meals is logged via
 * the normal compose flow, which also deducts the pantry.
 */
new class extends Component
{
    /** @var array<int, array<string, mixed>>|null */
    public ?array $suggestions = null;

    public bool $failed = false;

    public bool $loaded = false;

    private function cacheKey(): string
    {
        return 'ai-chef.'.Auth::id().'.'.now()->toDateString();
    }

    public function suggest(RecipeSuggester $chef, bool $fresh = false): void
    {
        $this->failed = false;

        if ($fresh) {
            Cache::forget($this->cacheKey());
        }

        $cached = Cache::get($this->cacheKey());

        if ($cached !== null) {
            $this->suggestions = $cached['suggestions'];
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

        // Resolve pantry names for display once, server-side.
        $names = PantryItem::with('canonicalProduct')
            ->whereIn('id', collect($ideas->suggestions)->flatMap(fn ($s) => $s['pantry_item_ids'])->unique())
            ->get()
            ->mapWithKeys(fn (PantryItem $i) => [$i->id => $i->canonicalProduct->name]);

        $this->suggestions = array_map(fn (array $s) => [
            ...$s,
            'uses' => array_values(array_filter(array_map(fn (int $id) => $names[$id] ?? null, $s['pantry_item_ids']))),
        ], $ideas->suggestions);

        Cache::put($this->cacheKey(), ['suggestions' => $this->suggestions], now()->endOfDay());
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

<div>
    @if ($chefAvailable)
        <x-app.module label="AI chef">
            <p class="voice-caption mt-2 text-ink-dim">Meal ideas from what you actually have — shaped by your goal and any allergies.</p>

            @if ($suggestions === null && ! $loaded)
                <div class="mt-3">
                    <x-app.console-key primary wire:click="suggest" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="suggest">Suggest today's meals</span>
                        <span wire:loading wire:target="suggest">Thinking about your pantry…</span>
                    </x-app.console-key>
                </div>
            @endif

            @if ($failed)
                <p class="mt-3 rounded bg-plate-well px-3 py-2 text-xs text-ink-dim">
                    Couldn't cook anything up — you may need a few more items in stock. Try again after your next scan.
                </p>
            @endif

            @if ($suggestions !== null)
                <div class="mt-3 space-y-3">
                    @foreach ($suggestions as $i => $idea)
                        <div class="rounded border border-seam bg-plate-well p-3.5" x-data="{ open: false }">
                            <p class="silkscreen">{{ strtoupper($idea['slot']) }}</p>
                            <p class="voice-item mt-1 text-ink">{{ $idea['title'] }}</p>
                            @if ($idea['summary'] !== '')
                                <p class="voice-caption mt-0.5 text-ink-dim">{{ $idea['summary'] }}</p>
                            @endif

                            <p class="data-sm mt-2 text-ink-faint">
                                @if (($idea['uses'] ?? []) !== [])
                                    FROM YOUR PANTRY: {{ strtoupper(implode(', ', $idea['uses'])) }}
                                @endif
                                @if ($idea['also_needed'] !== [])
                                    <span class="block">ALSO NEEDED: {{ strtoupper(implode(', ', $idea['also_needed'])) }}</span>
                                @endif
                            </p>

                            @php($figures = array_filter([
                                $idea['approx_calories'] !== null ? '~'.number_format($idea['approx_calories']).' KCAL' : null,
                                $idea['approx_protein'] !== null ? '~'.number_format($idea['approx_protein']).'G PROTEIN' : null,
                            ]))
                            @if ($figures !== [])
                                <p class="data-sm mt-1.5 text-ink-dim">{{ implode(' · ', $figures) }} <span class="text-ink-faint">EST.</span></p>
                            @endif

                            <button type="button" x-on:click="open = !open" class="keycap-sm mt-2.5 flex items-center gap-1.5 text-ink-dim transition hover:text-ink">
                                <span x-text="open ? 'Hide recipe' : 'Show recipe'"></span>
                                <span x-text="open ? '−' : '+'" class="data-sm"></span>
                            </button>
                            <ol x-show="open" x-cloak class="mt-2 list-decimal space-y-1.5 pl-5 text-sm text-ink-dim">
                                @foreach ($idea['steps'] as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3">
                    <x-app.console-key wire:click="freshIdeas" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="freshIdeas">Fresh ideas</span>
                        <span wire:loading wire:target="freshIdeas">Thinking…</span>
                    </x-app.console-key>
                </div>
                <p class="mt-2 text-xs text-ink-faint">Cooked one? Log it via Eat → Log a meal → Home-cooked and your pantry updates itself.</p>
            @endif
        </x-app.module>
    @endif
</div>
