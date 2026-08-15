<?php

use App\AI\Contracts\RecipeSuggester;
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
    /** chef | stock — the chef is the pantry's default face when configured. */
    public string $view = 'stock';

    // Manual-add form (visibility is client state; only the data lives here)
    public string $productSearch = '';

    public ?int $selectedProductId = null;

    public string $addQuantity = '1';

    public string $addUnit = QuantityUnit::Unit->value;

    public function mount(RecipeSuggester $chef): void
    {
        $this->view = $chef->available() ? 'chef' : 'stock';
    }

    public function showChef(): void
    {
        $this->view = 'chef';
    }

    public function showStock(): void
    {
        $this->view = 'stock';
    }

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
            ->sortBy(fn ($item) => $item->canonicalProduct->brand.' '.$item->canonicalProduct->name)
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
            'chefAvailable' => app(RecipeSuggester::class)->available(),
        ];
    }
}; ?>

    <div class="space-y-3" x-data="{ addOpen: false, added: false, toast: null, toastT: null }"
         x-on:pantry-updated.window="added = true; setTimeout(() => added = false, 2000)"
         x-on:consumption-logged.window="toast = 'logged'; clearTimeout(toastT); toastT = setTimeout(() => toast = null, 5000)"
         x-on:consumption-undone.window="toast = 'undone'; clearTimeout(toastT); toastT = setTimeout(() => toast = null, 2000)">
        <div class="flex items-center justify-between px-1">
            <div>
                <h1 class="voice-title text-ink">Pantry</h1>
                <p class="voice-caption mt-0.5 text-ink-dim">
                    {{ $view === 'chef' ? "Today's plan from what you have." : 'What food you currently have.' }}
                </p>
            </div>
            @if ($view === 'stock')
                <button type="button" x-on:click="addOpen = !addOpen"
                        :class="addOpen ? 'text-ink-dim' : 'key-action'"
                        class="key keycap-sm px-3.5 py-2.5">
                    <span x-text="addOpen ? 'Close' : 'Add item'">Add item</span>
                </button>
            @endif
        </div>

        {{-- View switch: the chef is the default face; the stock list is one key away. --}}
        @if ($chefAvailable)
            <div class="grid grid-cols-2 gap-2">
                <button type="button" wire:click="showChef"
                        class="key keycap-sm px-3 py-2.5 text-center {{ $view === 'chef' ? 'key-action' : 'text-ink-dim' }}">
                    Chef
                </button>
                <button type="button" wire:click="showStock"
                        class="key keycap-sm px-3 py-2.5 text-center {{ $view === 'stock' ? 'key-action' : 'text-ink-dim' }}">
                    Stock
                </button>
            </div>
        @endif

        {{-- CHEF — the proactive day plan (default when configured). --}}
        @if ($view === 'chef')
            <livewire:ai-chef />
        @endif

        @if ($view === 'stock')

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
                            <p class="mt-2 text-xs text-ink-dim">No products match. Try scanning its barcode — a scan can add new products to the database.</p>
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
                subtitle="Scan what you bought, or add an item manually — stock and remaining quantities show here." />
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
                               class="flex min-w-0 flex-1 items-center justify-between gap-4 px-5 py-3 transition hover:bg-plate-raised">
                                <span class="min-w-0">
                                    <span class="voice-caption block truncate text-ink">{{ $item->canonicalProduct->brand }} — {{ $item->canonicalProduct->name }}</span>
                                    @if ($item->canonicalProduct->variant)
                                        <span class="data-sm mt-0.5 block truncate text-ink-dim">{{ $item->canonicalProduct->variant }}</span>
                                    @endif
                                </span>
                                <span class="data-md shrink-0 text-ink">
                                    {{ rtrim(rtrim(number_format((float) $item->current_quantity, 3, '.', ''), '0'), '.') }}
                                    <span class="data-sm text-ink-faint uppercase">{{ $item->quantity_unit->shortLabelFor((float) $item->current_quantity) }}</span>
                                </span>
                            </a>
                            {{-- One tap logs the natural portion; the toast's Undo takes it back.
                                 Custom amounts live on the item page. --}}
                            <button type="button" wire:click="eatOne({{ $item->id }})" wire:loading.attr="disabled"
                                    aria-label="Eat one portion of {{ $item->canonicalProduct->name }}"
                                    class="key keycap-sm hit shrink-0 px-3 py-2 text-ink-dim">
                                Eat
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
        @endif

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
    </div>
