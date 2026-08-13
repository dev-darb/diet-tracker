<?php

use App\Models\PantryItem;
use App\Services\ConsumptionService;
use App\Services\PantryNutritionService;
use App\Services\PantryService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Pantry item detail + actions (BUILD_PLAN §7.9). Shows identity, current
 * quantity, computed nutrition (via NutritionCalculator, never inline maths),
 * purchase/expiry dates, and the Consume / Change quantity / Remove actions.
 *
 * Consume routes through ConsumptionService so eating is recorded as a
 * snapshotted consumption event AND deducted from the ledger (brief §8.1–§8.3);
 * Change quantity / Remove stay on PantryService (pure inventory corrections).
 */
new class extends Component {
    public PantryItem $pantryItem;

    public string $consumeAmount = '1';
    public string $newQuantity = '';
    public bool $confirmRemove = false;

    public function mount(PantryItem $pantryItem): void
    {
        abort_unless($pantryItem->user_id === Auth::id(), 403);

        $this->pantryItem = $pantryItem->load('canonicalProduct');
        $this->newQuantity = $this->currentQuantityString();
    }

    public function consumeOne(ConsumptionService $service): void
    {
        $this->applyConsume($service, 1.0);
    }

    public function consumeHalf(ConsumptionService $service): void
    {
        $balance = (float) $this->pantryItem->current_quantity;

        if ($balance > 0) {
            $this->applyConsume($service, round($balance / 2, 3));
        }
    }

    public function consumeAll(ConsumptionService $service): void
    {
        $balance = (float) $this->pantryItem->current_quantity;

        if ($balance > 0) {
            $this->applyConsume($service, round($balance, 3));
        }
    }

    public function consume(ConsumptionService $service): void
    {
        $data = $this->validate(['consumeAmount' => ['required', 'numeric', 'gt:0']]);
        $this->applyConsume($service, (float) $data['consumeAmount']);
    }

    public function changeQuantity(PantryService $service): void
    {
        $data = $this->validate(['newQuantity' => ['required', 'numeric', 'min:0']]);

        $service->correct($this->pantryItem, (float) $data['newQuantity']);
        $this->refreshItem();
        $this->dispatch('item-changed');
    }

    public function remove(PantryService $service): void
    {
        $balance = (float) $this->pantryItem->current_quantity;

        if ($balance > 0) {
            $service->manualRemove($this->pantryItem, $balance);
        }

        $this->redirectRoute('pantry', navigate: true);
    }

    private function applyConsume(ConsumptionService $service, float $amount): void
    {
        $service->consumePantryItem(Auth::user(), $this->pantryItem, $amount);
        $this->refreshItem();
        $this->consumeAmount = '1';
        $this->dispatch('item-changed');
    }

    private function refreshItem(): void
    {
        $this->pantryItem->refresh();
        $this->newQuantity = $this->currentQuantityString();
    }

    private function currentQuantityString(): string
    {
        return rtrim(rtrim(number_format((float) $this->pantryItem->current_quantity, 3, '.', ''), '0'), '.');
    }

    public function with(PantryNutritionService $nutrition): array
    {
        $values = $nutrition->currentNutrition($this->pantryItem);

        return [
            'nutrition' => $values?->rounded(1),
            'hasNutrition' => $values !== null,
        ];
    }
}; ?>

<x-layouts.app :title="__('Item')">
    <div class="space-y-5" x-data="{ toast: false }"
         x-on:item-changed.window="toast = true; setTimeout(() => toast = false, 2000)">

        <a href="{{ route('pantry') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-800">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
            Pantry
        </a>

        {{-- Identity + current quantity --}}
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">{{ $pantryItem->canonicalProduct->brand }}</h1>
            <p class="mt-0.5 text-base text-zinc-700">{{ $pantryItem->canonicalProduct->name }}</p>
            @if ($pantryItem->canonicalProduct->variant)
                <p class="mt-0.5 text-sm text-zinc-500">{{ $pantryItem->canonicalProduct->variant }}</p>
            @endif

            <div class="mt-4 flex items-baseline gap-2">
                <span class="text-3xl font-bold tabular-nums text-emerald-700">{{ rtrim(rtrim(number_format((float) $pantryItem->current_quantity, 3, '.', ''), '0'), '.') }}</span>
                <span class="text-sm font-medium text-zinc-500">{{ $pantryItem->quantity_unit->shortLabel() }} remaining</span>
            </div>

            <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-zinc-500">
                @if ($pantryItem->purchased_at)
                    <div><dt class="inline">Purchased</dt> <dd class="inline font-medium text-zinc-700">{{ $pantryItem->purchased_at->format('j M Y') }}</dd></div>
                @endif
                @if ($pantryItem->expiry_date)
                    <div><dt class="inline">Expires</dt> <dd class="inline font-medium text-zinc-700">{{ $pantryItem->expiry_date->format('j M Y') }}</dd></div>
                @endif
            </dl>
        </div>

        {{-- Nutrition (computed by NutritionCalculator for what's currently held) --}}
        <section class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-zinc-900">Nutrition in what you have</h2>
            @if ($hasNutrition)
                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        ['Calories', $nutrition->calories, 'kcal'],
                        ['Protein', $nutrition->protein, 'g'],
                        ['Carbs', $nutrition->carbs, 'g'],
                        ['Sugars', $nutrition->sugars, 'g'],
                        ['Fat', $nutrition->fat, 'g'],
                        ['Saturated', $nutrition->saturatedFat, 'g'],
                        ['Fibre', $nutrition->fibre, 'g'],
                        ['Salt', $nutrition->salt, 'g'],
                    ] as [$label, $value, $unit])
                        <div class="rounded-xl bg-zinc-50 px-3 py-2.5">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">{{ $label }}</p>
                            <p class="mt-0.5 text-sm font-semibold text-zinc-900">{{ rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') }} <span class="text-xs font-normal text-zinc-500">{{ $unit }}</span></p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-2 text-sm text-zinc-500">Nutrition isn't available for this item yet.</p>
            @endif
        </section>

        {{-- Actions --}}
        <section class="space-y-4 rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-zinc-900">Actions</h2>

            {{-- Consume — records a snapshotted consumption event + deducts stock --}}
            @php($outOfStock = (float) $pantryItem->current_quantity <= 0)
            <div class="space-y-3">
                <p class="text-xs font-medium text-zinc-600">Consume <span class="font-normal text-zinc-400">(logs it to today)</span></p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="consumeOne" @disabled($outOfStock)
                            class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-40">
                        I ate one
                    </button>
                    <button type="button" wire:click="consumeHalf" @disabled($outOfStock)
                            class="rounded-xl border border-zinc-200 px-4 py-2.5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40">
                        Half
                    </button>
                    <button type="button" wire:click="consumeAll" @disabled($outOfStock)
                            class="rounded-xl border border-zinc-200 px-4 py-2.5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40">
                        All
                    </button>
                </div>
                <div class="flex items-end gap-3">
                    <div class="w-24">
                        <label class="text-xs font-medium text-zinc-600">Custom ({{ $pantryItem->quantity_unit->shortLabel() }})</label>
                        <input type="number" step="any" min="0" inputmode="decimal" wire:model="consumeAmount"
                               class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <button type="button" wire:click="consume" @disabled($outOfStock)
                            class="rounded-xl border border-zinc-200 px-4 py-2.5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40">
                        Consume amount
                    </button>
                </div>
                @error('consumeAmount') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Change quantity --}}
            <div class="space-y-2 border-t border-zinc-100 pt-4">
                <p class="text-xs font-medium text-zinc-600">Change quantity <span class="font-normal text-zinc-400">(set the true amount)</span></p>
                <div class="flex items-end gap-3">
                    <div class="w-28">
                        <input type="number" step="any" min="0" inputmode="decimal" wire:model="newQuantity"
                               class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <button type="button" wire:click="changeQuantity"
                            class="rounded-xl border border-zinc-200 px-4 py-2.5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50">
                        Update
                    </button>
                </div>
                @error('newQuantity') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Remove --}}
            <div class="border-t border-zinc-100 pt-4">
                @if ($confirmRemove)
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="remove"
                                class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700">
                            Confirm remove
                        </button>
                        <button type="button" wire:click="$set('confirmRemove', false)" class="text-sm font-medium text-zinc-500 hover:text-zinc-800">Cancel</button>
                    </div>
                @else
                    <button type="button" wire:click="$set('confirmRemove', true)"
                            class="text-sm font-semibold text-red-600 hover:text-red-700">
                        Remove from pantry
                    </button>
                @endif
            </div>
        </section>

        <div x-show="toast" x-cloak class="fixed inset-x-0 bottom-24 z-40 mx-auto max-w-md px-5">
            <div class="rounded-xl bg-zinc-900 px-4 py-2.5 text-center text-sm font-medium text-white shadow-lg">Pantry updated</div>
        </div>
    </div>
</x-layouts.app>
