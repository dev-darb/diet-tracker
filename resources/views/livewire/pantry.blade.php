<?php

use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Services\PantryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

/**
 * Pantry — "what food do I currently have?" (BUILD_PLAN §7.8). Lists the user's
 * stock with the current balance, and offers manual add (pick a canonical
 * product + quantity) as the pre-Scan path (§20 Phase 1). All mutations go
 * through PantryService so the ledger + cached balance stay correct.
 */
new class extends Component {
    // Manual-add form
    public bool $showAdd = false;
    public string $productSearch = '';
    public ?int $selectedProductId = null;
    public string $addQuantity = '1';
    public string $addUnit = QuantityUnit::Unit->value;

    public function toggleAdd(): void
    {
        $this->showAdd = ! $this->showAdd;
        $this->reset(['productSearch', 'selectedProductId', 'addQuantity', 'addUnit']);
        $this->addQuantity = '1';
        $this->addUnit = QuantityUnit::Unit->value;
        $this->resetValidation();
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

        $this->toggleAdd();
        $this->dispatch('pantry-updated');
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
        if ($this->showAdd && $this->selectedProductId === null && $term !== '') {
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

<x-layouts.app :title="__('Pantry')">
    <div class="space-y-5">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">Pantry</h1>
                <p class="mt-1 text-sm text-zinc-500">What food you currently have.</p>
            </div>
            <button type="button" wire:click="toggleAdd"
                    class="rounded-xl bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
                {{ $showAdd ? 'Close' : 'Add item' }}
            </button>
        </div>

        {{-- Manual add (pre-Scan path) --}}
        @if ($showAdd)
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-zinc-900">Add to pantry</h2>

                @if ($selectedProduct)
                    <div class="mt-3 flex items-center justify-between rounded-xl bg-zinc-50 px-3 py-2.5">
                        <span class="text-sm font-medium text-zinc-900">{{ $selectedProduct->brand }} — {{ $selectedProduct->name }}</span>
                        <button type="button" wire:click="clearSelection" class="text-xs font-medium text-zinc-500 hover:text-zinc-800">Change</button>
                    </div>
                @else
                    <div class="mt-3">
                        <input type="search" wire:model.live.debounce.300ms="productSearch" placeholder="Search products…"
                               class="w-full rounded-xl border border-zinc-200 px-3.5 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        @if (count($matches) > 0)
                            <div class="mt-2 divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-100">
                                @foreach ($matches as $match)
                                    <button type="button" wire:click="selectProduct({{ $match->id }})"
                                            class="flex w-full items-center justify-between px-3.5 py-2.5 text-left text-sm transition hover:bg-zinc-50">
                                        <span class="text-zinc-900">{{ $match->brand }} — {{ $match->name }}</span>
                                        <span class="text-emerald-600">Select</span>
                                    </button>
                                @endforeach
                            </div>
                        @elseif (trim($productSearch) !== '')
                            <p class="mt-2 text-xs text-zinc-500">No products match. An admin can add it in the console.</p>
                        @endif
                    </div>
                @endif
                @error('selectedProductId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="mt-4 flex items-end gap-3">
                    <div class="w-24">
                        <label class="text-xs font-medium text-zinc-600">Quantity</label>
                        <input type="number" step="any" min="0" inputmode="decimal" wire:model="addQuantity"
                               class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-medium text-zinc-600">Unit</label>
                        <select wire:model="addUnit"
                                class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @foreach ($unitOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @error('addQuantity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <button type="button" wire:click="add"
                        class="mt-4 w-full rounded-xl bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800">
                    Add to pantry
                </button>
            </section>
        @endif

        {{-- Pantry list --}}
        @if ($items->isEmpty())
            <x-app.placeholder
                title="Your pantry is empty"
                subtitle="Add an item to start tracking what you own. Items will show here with how much is left.">
                <x-slot:icon>
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                    </svg>
                </x-slot:icon>
            </x-app.placeholder>
        @else
            <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm">
                @foreach ($items as $item)
                    <a href="{{ route('pantry.item', $item) }}" wire:navigate
                       class="flex items-center justify-between gap-4 border-b border-zinc-100 px-5 py-4 transition last:border-b-0 hover:bg-zinc-50">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-zinc-900">{{ $item->canonicalProduct->brand }} — {{ $item->canonicalProduct->name }}</p>
                            @if ($item->canonicalProduct->variant)
                                <p class="mt-0.5 truncate text-xs text-zinc-500">{{ $item->canonicalProduct->variant }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="whitespace-nowrap rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                {{ rtrim(rtrim(number_format((float) $item->current_quantity, 3, '.', ''), '0'), '.') }} {{ $item->quantity_unit->shortLabel() }} left
                            </span>
                            <svg class="size-4 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
