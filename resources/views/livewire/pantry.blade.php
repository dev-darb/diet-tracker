<?php

use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\PantryItem;
use App\Services\ConsumptionService;
use App\Services\PantryService;
use App\Services\PortionSuggestionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Pantry — "what food do I currently have?" (BUILD_PLAN §7.8). Lists the user's
 * stock with the current balance, and offers manual add (pick a canonical
 * product + quantity) as the pre-Scan path (§20 Phase 1). All mutations go
 * through PantryService so the ledger + cached balance stay correct.
 */
new #[Layout('components.layouts.app', ['title' => 'Pantry'])] class extends Component
{
    // Manual-add form (visibility is client state; only the data lives here)
    public string $productSearch = '';

    public ?int $selectedProductId = null;

    public string $addQuantity = '1';

    public string $addUnit = QuantityUnit::Unit->value;

    public function selectProduct(int $productId): void
    {
        $this->selectedProductId = $productId;
        $this->productSearch = '';
    }

    public function clearSelection(): void
    {
        $this->selectedProductId = null;
    }

    public function add(PantryService $service): void
    {
        $data = $this->validate([
            'selectedProductId' => ['required', Rule::exists('canonical_products', 'id')],
            'addQuantity' => ['required', 'numeric', 'gt:0'],
            'addUnit' => ['required', Rule::enum(QuantityUnit::class)],
        ], [], ['selectedProductId' => 'product']);

        $product = CanonicalProduct::findOrFail($data['selectedProductId']);

        $service->purchase(
            Auth::user(),
            $product,
            (float) $data['addQuantity'],
            QuantityUnit::from($data['addUnit']),
        );

        // Stay open for the next item — unloading a shop is a run, not a one-off.
        $this->reset(['productSearch', 'selectedProductId', 'addQuantity', 'addUnit']);
        $this->addQuantity = '1';
        $this->addUnit = QuantityUnit::Unit->value;
        $this->resetValidation();
        $this->dispatch('pantry-updated');
    }

    /** The just-logged event, so the toast's Undo can reach it. */
    public ?int $lastConsumptionId = null;

    /**
     * Row-level "eat": logging what you just ate should not need a page. The
     * amount is the item's top natural portion, re-derived server-side
     * (PortionSuggestionService) — the client sends only the item id.
     */
    public function eatOne(PortionSuggestionService $portions, ConsumptionService $consumption, int $itemId): void
    {
        $item = PantryItem::with('canonicalProduct')->findOrFail($itemId);

        abort_unless($item->user_id === Auth::id(), 403);

        $choice = $portions->suggestionsFor($item, Auth::user())[0] ?? null;

        if ($choice === null || $choice['quantity'] <= 0 || $choice['quantity'] > (float) $item->current_quantity + 1e-9) {
            // An encouraged action must never fail silently: say why, point at
            // the custom-amount path on the item page.
            $this->dispatch('consumption-blocked');

            return;
        }

        $event = $consumption->consumePantryItem(Auth::user(), $item, $choice['quantity'], portionLabel: $choice['record']);
        $this->lastConsumptionId = $event->id;
        $this->dispatch('consumption-logged');
    }

    /** Reverse the row-level eat — the ledger restores the stock. */
    public function undoEat(ConsumptionService $consumption): void
    {
        $event = Auth::user()->consumptionEvents()->find($this->lastConsumptionId);

        if ($event !== null) {
            $consumption->deleteConsumption($event);
        }

        $this->lastConsumptionId = null;
        $this->dispatch('consumption-undone');
    }

    public function with(): array
    {
        $items = Auth::user()->pantryItems()
            ->with('canonicalProduct')
            ->where('current_quantity', '>', 0)
            ->get()
            ->sortBy(fn ($item) => $item->canonicalProduct->displayName())
            ->values();

        $matches = [];
        $term = trim($this->productSearch);
        if ($this->selectedProductId === null && $term !== '') {
            $like = '%'.$term.'%';
            $matches = CanonicalProduct::query()
                ->where(fn ($q) => $q->where('brand', 'like', $like)->orWhere('name', 'like', $like)->orWhere('gtin', 'like', $like))
                ->orderBy('brand')->orderBy('name')
                ->limit(8)->get();
        }

        return [
            'items' => $items,
            'matches' => $matches,
            'selectedProduct' => $this->selectedProductId ? CanonicalProduct::find($this->selectedProductId) : null,
            'unitOptions' => QuantityUnit::options(),
        ];
    }
}; ?>

    <div class="space-y-3" x-data="{ addOpen: false, added: false, toast: null, toastT: null }"
         x-on:pantry-updated.window="added = true; setTimeout(() => added = false, 2000)"
         x-on:consumption-logged.window="toast = 'logged'; clearTimeout(toastT); toastT = setTimeout(() => toast = null, 5000)"
         x-on:consumption-undone.window="toast = 'undone'; clearTimeout(toastT); toastT = setTimeout(() => toast = null, 2000)"
         x-on:consumption-blocked.window="toast = 'blocked'; clearTimeout(toastT); toastT = setTimeout(() => toast = null, 3000)">
        <div class="flex items-center justify-between px-1">
            <div>
                <h1 class="voice-title text-ink">Pantry</h1>
            </div>
            <button type="button" x-on:click="addOpen = !addOpen"
                    :class="addOpen ? 'text-ink-dim' : 'key-action'"
                    class="key keycap-sm px-3.5 py-2.5">
                <span x-text="addOpen ? 'Close' : 'Add item'">Add item</span>
            </button>
        </div>

        {{-- Manual add (pre-Scan path) — opens instantly, stays open for the
             next item so a shop unloads in one run. SPLIT: the desk parts to
             reveal the form; inert while closed so nothing hidden is tabbable.
             The closed -mt-3 hands its stack gap back to the space-y flow. --}}
        <div class="split" :class="addOpen ? 'split-open' : '-mt-3'" :inert="!addOpen">
        <div>
        <div class="pt-px">
            <x-app.module label="Add to pantry">

                @if ($selectedProduct)
                    <div class="well mt-3 flex items-center justify-between gap-3 px-3.5 py-2.5">
                        <span class="min-w-0 truncate text-sm text-ink">{{ $selectedProduct->brand }} — {{ $selectedProduct->name }}</span>
                        <button type="button" wire:click="clearSelection" class="keycap-sm hit shrink-0 text-ink-dim transition hover:text-ink">Change</button>
                    </div>
                @else
                    <div class="mt-3">
                        <input type="search" wire:model.live.debounce.300ms="productSearch" placeholder="Search products…" aria-label="Search products" maxlength="80"
                               class="input-well">
                        @if (count($matches) > 0)
                            <ul class="well mt-2 divide-y divide-seam overflow-hidden">
                                @foreach ($matches as $match)
                                    <li>
                                        <button type="button" wire:click="selectProduct({{ $match->id }})"
                                                class="flex w-full items-center justify-between gap-3 px-3.5 py-2.5 text-left text-sm transition hover:bg-plate-raised">
                                            <span class="min-w-0 truncate text-ink">{{ $match->brand }} — {{ $match->name }}</span>
                                            <span class="keycap-sm shrink-0 text-action">Select</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif (trim($productSearch) !== '')
                            <p class="mt-2 text-xs text-ink-dim">No match — scan it to add it.</p>
                        @endif
                    </div>
                @endif
                @error('selectedProductId') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror

                <div class="mt-4 flex items-end gap-3">
                    <div class="w-24">
                        <label class="silkscreen" for="add-qty">Quantity</label>
                        <input id="add-qty" type="number" step="any" min="0" max="100000" inputmode="decimal" wire:model="addQuantity"
                               class="input-well data mt-1.5">
                    </div>
                    <div class="flex-1">
                        <label class="silkscreen" for="add-unit">Unit</label>
                        <select id="add-unit" wire:model="addUnit"
                                class="input-well data mt-1.5">
                            @foreach ($unitOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @error('addQuantity') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror

                <x-app.console-key primary wire:click="add" wire:loading.attr="disabled" class="mt-4">
                    Add to pantry
                </x-app.console-key>
            </x-app.module>
        </div>
        </div>
        </div>

        {{-- Pantry list: dense data rows, quantity as a readout. --}}
        @if ($items->isEmpty())
            <x-app.placeholder
                status="EMPTY"
                title="Your pantry is empty"
                subtitle="Scan what you bought, or add an item manually." />
        @else
            <section class="module px-0 pb-1 pt-4">
                <div class="flex items-baseline justify-between px-5">
                    <h2 class="silkscreen">Stock</h2>
                    <span class="data-sm text-ink-faint">{{ $items->count() }} {{ $items->count() === 1 ? 'ITEM' : 'ITEMS' }}</span>
                </div>
                <ul class="mt-2 divide-y divide-seam">
                    @foreach ($items as $item)
                        <li class="flex min-h-[44px] items-center gap-2 pr-3">
                            <a href="{{ route('pantry.item', $item) }}" wire:navigate
                               class="flex min-w-0 flex-1 items-center gap-3 px-5 py-2.5 transition hover:bg-plate-raised">
                                {{-- Leading identity (One Row Molecule): the food's
                                     own image where one exists; a quiet mono
                                     monogram where none can. Ingredients should
                                     look like food, not like icons. --}}
                                @if ($item->canonicalProduct->primary_image_path)
                                    <img src="{{ $item->canonicalProduct->primary_image_path }}" alt="" loading="lazy"
                                         class="size-9 shrink-0 rounded bg-plate-well object-cover">
                                @else
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded bg-plate-well">
                                        <span class="data-sm text-ink-faint">{{ mb_strtoupper(mb_substr($item->canonicalProduct->name, 0, 1)) }}</span>
                                    </span>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span class="voice-caption block truncate text-ink">{{ $item->canonicalProduct->displayName() }}</span>
                                    @if ($item->canonicalProduct->variant)
                                        <span class="data-sm mt-0.5 block truncate text-ink-dim">{{ $item->canonicalProduct->variant }}</span>
                                    @endif
                                </span>
                                {{-- The landing: when EAT just moved this number, it glows
                                     good and settles — the proof the action counted. The
                                     wire:key carries the value so only a real change re-mounts
                                     (and re-fires) the readout; the recency gate keeps page
                                     loads calm. --}}
                                @php($justMoved = $item->updated_at->gt(now()->subSeconds(8)))
                                @php($daysLeft = $item->expiry_date !== null ? (int) now()->startOfDay()->diffInDays($item->expiry_date->startOfDay(), false) : null)
                                <span class="flex shrink-0 flex-col items-end">
                                    <span class="data-md text-ink {{ $justMoved ? 'value-settle' : '' }}"
                                          wire:key="qty-{{ $item->id }}-{{ $item->current_quantity }}">
                                        {{ rtrim(rtrim(number_format((float) $item->current_quantity, 3, '.', ''), '0'), '.') }}
                                        <span class="data-sm text-ink-faint uppercase">{{ $item->quantity_unit->shortLabelFor((float) $item->current_quantity) }}</span>
                                    </span>
                                    {{-- Time is a signal (research §7.10): expiry is a
                                         data value on the row — amber only when
                                         imminent, never an alarm. --}}
                                    @if ($daysLeft !== null && $daysLeft <= 7)
                                        <span class="data-micro mt-0.5 uppercase {{ $daysLeft <= 1 ? 'text-low' : 'text-ink-faint' }}">
                                            {{ $daysLeft < 0 ? 'Past best' : ($daysLeft === 0 ? 'Use today' : $daysLeft.'d left') }}
                                        </span>
                                    @endif
                                </span>
                            </a>
                            {{-- One tap logs the natural portion; the toast's Undo takes it back.
                                 Custom amounts live on the item page. --}}
                            <button type="button" wire:click="eatOne({{ $item->id }})" wire:loading.attr="disabled"
                                    aria-label="Eat one portion of {{ $item->canonicalProduct->name }}"
                                    class="key keycap hit shrink-0 border-seam-strong px-3.5 py-2.5 text-ink">
                                Eat
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- The RESIDENT CHEF (tranche 4): a little chef living in the pantry
             who knows the stock and suggests the likeliest current meal.
             Inventory first — the chef sits under the shelf, never above it. --}}
        <livewire:pantry-chef />

        <x-app.stamp-toast show="added">Added to stock</x-app.stamp-toast>

        {{-- Row-level eat: logged automatically, with the way back in hand. --}}
        <div x-show="toast === 'logged'" x-cloak role="status" class="fixed inset-x-0 bottom-28 z-40 mx-auto max-w-md px-5">
            <div class="stamp-in flex items-center justify-between gap-3 rounded-md bg-good px-4 py-3 text-black">
                <span class="flex items-center gap-2.5">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5.5 5.5L20 6.5" /></svg>
                    <span class="keycap">Logged to today</span>
                </span>
                <button type="button"
                        x-on:click="toast = null; clearTimeout(toastT); $wire.undoEat()"
                        class="keycap hit shrink-0 underline decoration-2 underline-offset-4">
                    Undo
                </button>
            </div>
        </div>
        <x-app.stamp-toast show="toast === 'undone'" tone="neutral">Removed — stock restored</x-app.stamp-toast>
        <x-app.stamp-toast show="toast === 'blocked'" tone="neutral">Not enough left — open the item</x-app.stamp-toast>
    </div>
