<?php

use App\AI\Contracts\RecipeSuggester;
use App\Models\ChefSuggestion;
use App\Models\PantryItem;
use App\Services\ChefService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The resident chef card (tranche 4, Aug 2026) — a compact decision moment
 * seated on the Pantry's stock face. Time-of-day aware: it shows the
 * likeliest CURRENT meal, grounded in what's actually on the shelf.
 *
 * NEVER blocking (product principle 7): the pantry renders instantly; this
 * island loads via wire:init from the durable chef_suggestions artifact —
 * the model is consulted only when today's slot has no row yet (or on an
 * explicit "Another idea"), inside this deferred request. Keyless → the
 * card simply doesn't exist. "Cooked this" bridges into the deterministic
 * compose flow and links the logged event back here.
 */
new class extends Component
{
    public bool $loaded = false;

    public function load(): void
    {
        $this->loaded = true;
    }

    public function anotherIdea(ChefService $chef): void
    {
        $chef->refresh(Auth::user());
    }

    public function with(ChefService $chef, RecipeSuggester $suggester): array
    {
        if (! $this->loaded || ! $suggester->available()) {
            return ['available' => $suggester->available(), 'suggestion' => null, 'stockThumbs' => collect()];
        }

        $user = Auth::user();
        $suggestion = $chef->current($user) ?? $chef->plan($user);

        // The in-stock ingredients' own imagery — the dish leads with the
        // real food it's made from (Food Is the Hero Image).
        $stockThumbs = $suggestion !== null
            ? PantryItem::query()->with('canonicalProduct')
                ->whereIn('id', array_slice($suggestion->inStockItemIds(), 0, 4))
                ->where('user_id', $user->id)
                ->get()
            : collect();

        return [
            'available' => true,
            'suggestion' => $suggestion,
            'stockThumbs' => $stockThumbs,
        ];
    }
}; ?>

<div wire:init="load">
    @if ($available)
        <div wire:loading.delay wire:target="load, anotherIdea">
            <div class="module flex items-center gap-3 px-5 py-3.5">
                <span class="size-2 shrink-0 animate-pulse rounded-full bg-info" aria-hidden="true"></span>
                <span class="silkscreen">Chef</span>
                <span class="voice-caption text-ink-dim">Looking at your shelf…</span>
            </div>
        </div>

        <div wire:loading.remove wire:target="load, anotherIdea">
            @if ($suggestion !== null)
                <div class="module px-5 pb-4 pt-4" x-data="{ open: false }">
                    <div class="flex items-baseline justify-between">
                        <h2 class="silkscreen">Chef · {{ $suggestion->slot }}</h2>
                        @php($figures = array_filter([
                            $suggestion->approx_calories !== null ? '~'.number_format($suggestion->approx_calories).' KCAL' : null,
                            $suggestion->approx_protein !== null ? '~'.number_format($suggestion->approx_protein).'G' : null,
                        ]))
                        @if ($figures !== [])
                            <span class="data-sm text-ink-faint">{{ implode(' · ', $figures) }} EST.</span>
                        @endif
                    </div>

                    <div class="mt-2.5 flex items-center gap-3">
                        {{-- The real food it's made from, off your own shelf. --}}
                        @if ($stockThumbs->isNotEmpty())
                            <div class="flex shrink-0 -space-x-2">
                                @foreach ($stockThumbs as $thumbItem)
                                    @if ($thumbItem->canonicalProduct->primary_image_path)
                                        <img src="{{ $thumbItem->canonicalProduct->primary_image_path }}" alt="" loading="lazy"
                                             class="size-9 rounded-full border-2 border-plate bg-plate-well object-cover">
                                    @else
                                        <span class="flex size-9 items-center justify-center rounded-full border-2 border-plate bg-plate-well">
                                            <span class="data-micro text-ink-faint">{{ mb_strtoupper(mb_substr($thumbItem->canonicalProduct->name, 0, 1)) }}</span>
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        <div class="min-w-0">
                            <p class="voice-item text-ink">{{ $suggestion->title }}</p>
                            @if ($suggestion->summary)
                                <p class="voice-caption mt-0.5 text-ink-dim">{{ $suggestion->summary }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($suggestion->isCooked())
                            <span class="chip stamp-in border-good/40 text-good">COOKED · LOGGED</span>
                        @else
                            <a href="{{ route('eat.log', ['chef' => $suggestion->id]) }}" wire:navigate
                               class="key key-action keycap-sm px-3.5 py-2">Cooked this</a>
                            <button type="button" x-on:click="open = !open"
                                    class="key keycap-sm hit px-3.5 py-2 text-ink-dim">
                                <span x-text="open ? 'Hide' : 'What goes in'">What goes in</span>
                            </button>
                            <button type="button" wire:click="anotherIdea" wire:loading.attr="disabled"
                                    class="keycap-sm hit px-2 py-2 text-ink-faint transition hover:text-ink">
                                Another idea
                            </button>
                        @endif
                    </div>

                    {{-- The working: ingredients (in-stock lit), upgrades, recipe. --}}
                    <div class="split" :class="open && 'split-open'">
                    <div>
                    <div class="mt-3 border-t border-seam pt-2.5">
                        <ul class="divide-y divide-seam">
                            @foreach ($suggestion->ingredients as $ingredient)
                                @php($inStock = ($ingredient['pantry_item_id'] ?? null) !== null)
                                <li class="flex min-h-[36px] items-center gap-2.5 py-1.5">
                                    <span aria-hidden="true" class="size-1.5 shrink-0 rounded-full {{ $inStock ? 'bg-good' : 'bg-seam-strong' }}"></span>
                                    <span class="voice-caption min-w-0 flex-1 truncate {{ $inStock ? 'text-ink' : 'text-ink-dim' }}">{{ $ingredient['name'] }}</span>
                                    @if (($ingredient['amount'] ?? '') !== '')
                                        <span class="data-sm shrink-0 text-ink-dim uppercase">{{ $ingredient['amount'] }}</span>
                                    @endif
                                    <span class="data-micro shrink-0 {{ $inStock ? 'text-good' : 'text-ink-faint' }} uppercase">{{ $inStock ? 'In stock' : 'To get' }}</span>
                                </li>
                            @endforeach
                        </ul>

                        @if ($suggestion->upgrades !== [])
                            <p class="silkscreen mt-3">Make it better</p>
                            <ul class="mt-1 space-y-1">
                                @foreach ($suggestion->upgrades as $upgrade)
                                    <li class="voice-caption text-ink-dim">+ {{ $upgrade }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($suggestion->steps !== [])
                            <p class="silkscreen mt-3">Recipe</p>
                            <ol class="mt-1.5 list-decimal space-y-1.5 pl-5 text-sm text-ink-dim">
                                @foreach ($suggestion->steps as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                    </div>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
