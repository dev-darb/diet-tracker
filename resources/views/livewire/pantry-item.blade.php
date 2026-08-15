<?php

use App\Models\PantryItem;
use App\Services\ConsumptionService;
use App\Services\PantryNutritionService;
use App\Services\PantryService;
use App\Services\PortionSuggestionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
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
new #[Layout('components.layouts.app', ['title' => 'Item'])] class extends Component
{
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

    /**
     * Consume via a natural-portion chip. The chip list is re-derived
     * server-side from its index — the client never supplies a quantity, so the
     * amount eaten is always the deterministic one the service computed.
     */
    public function consumePortion(PortionSuggestionService $portions, ConsumptionService $service, int $index): void
    {
        $options = $portions->suggestionsFor($this->pantryItem, Auth::user());
        $choice = $options[$index] ?? null;

        if ($choice === null || $choice['quantity'] <= 0) {
            return;
        }

        $this->applyConsume($service, $choice['quantity'], $choice['record']);
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

        $this->redirectRoute('pantry');
    }

    /** The just-logged event, so the toast's Undo can reach it. */
    public ?int $lastConsumptionId = null;

    /**
     * Undo the consumption the toast is showing: the event is deleted and the
     * ledger's compensating correction restores the stock automatically.
     */
    public function undoConsume(ConsumptionService $service): void
    {
        $event = Auth::user()->consumptionEvents()->find($this->lastConsumptionId);

        if ($event !== null) {
            $service->deleteConsumption($event);
        }

        $this->lastConsumptionId = null;
        $this->refreshItem();
        $this->dispatch('consumption-undone');
    }

    private function applyConsume(ConsumptionService $service, float $amount, ?string $portionLabel = null): void
    {
        $event = $service->consumePantryItem(Auth::user(), $this->pantryItem, $amount, portionLabel: $portionLabel);
        $this->lastConsumptionId = $event->id;
        $this->refreshItem();
        $this->consumeAmount = '1';
        $this->dispatch('consumption-logged');
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

    public function with(PantryNutritionService $nutrition, PortionSuggestionService $portions): array
    {
        $values = $nutrition->currentNutrition($this->pantryItem);

        return [
            'nutrition' => $values?->rounded(1),
            'hasNutrition' => $values !== null,
            'portions' => $portions->suggestionsFor($this->pantryItem, Auth::user()),
        ];
    }
}; ?>

    <div class="space-y-5" x-data="{ toast: null, toastT: null }"
         x-on:consumption-logged.window="toast = 'logged'; clearTimeout(toastT); toastT = setTimeout(() => toast = null, 5000)"
         x-on:consumption-undone.window="toast = 'undone'; clearTimeout(toastT); toastT = setTimeout(() => toast = null, 2000)"
         x-on:item-changed.window="toast = 'saved'; clearTimeout(toastT); toastT = setTimeout(() => toast = null, 2000)">

        <a href="{{ route('pantry') }}" wire:navigate class="keycap-sm inline-flex items-center gap-1.5 px-1 text-ink-dim transition hover:text-ink">
            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
            Pantry
        </a>

        {{-- Identity + current quantity readout --}}
        <x-app.module label="Item">
            <h1 class="voice-item mt-3 text-ink">{{ $pantryItem->canonicalProduct->brand }} <span class="text-ink-dim">{{ $pantryItem->canonicalProduct->name }}</span></h1>
            @if ($pantryItem->canonicalProduct->variant)
                <p class="voice-caption mt-0.5 text-ink-dim">{{ $pantryItem->canonicalProduct->variant }}</p>
            @endif

            <p class="mt-5 flex items-baseline gap-2">
                {{-- The landing: a consume/correction just moved this reading —
                     it glows good and settles (value-settle; keyed on value so
                     only real changes re-fire; recency keeps page loads calm). --}}
                <span class="data-xl text-ink {{ $pantryItem->updated_at->gt(now()->subSeconds(8)) ? 'value-settle' : '' }}"
                      wire:key="item-qty-{{ $pantryItem->current_quantity }}">{{ rtrim(rtrim(number_format((float) $pantryItem->current_quantity, 3, '.', ''), '0'), '.') }}</span>
                <span class="data-md text-ink-dim uppercase">{{ $pantryItem->quantity_unit->shortLabelFor((float) $pantryItem->current_quantity) }} remaining</span>
            </p>

            @if ($pantryItem->purchased_at || $pantryItem->expiry_date)
                <p class="data-sm mt-3 text-ink-faint uppercase">
                    @if ($pantryItem->purchased_at) Purchased {{ $pantryItem->purchased_at->format('j M Y') }} @endif
                    @if ($pantryItem->purchased_at && $pantryItem->expiry_date) · @endif
                    @if ($pantryItem->expiry_date) Expires {{ $pantryItem->expiry_date->format('j M Y') }} @endif
                </p>
            @endif
        </x-app.module>

        {{-- Consume — the daily action, and a win when it lands. --}}
        @php($outOfStock = (float) $pantryItem->current_quantity <= 0)
        <x-app.module label="Consume">
            {{-- Natural portions: chips derived server-side from the product's
                 pack/serving and the user's own last portion. Labels are
                 presentation; the quantity is deterministic (PortionSuggestionService). --}}
            <div class="mt-3 grid grid-cols-2 gap-2">
                @foreach ($portions as $i => $portion)
                    @php($tooBig = $portion['quantity'] > (float) $pantryItem->current_quantity + 1e-9)
                    <button type="button" wire:click="consumePortion({{ $i }})" wire:loading.attr="disabled"
                            @disabled($outOfStock || $tooBig)
                            class="key keycap-sm px-3 py-3 text-center disabled:cursor-not-allowed disabled:opacity-40 {{ $i === 0 ? 'key-action' : 'text-ink-dim' }}">
                        <span class="block">{{ $portion['label'] }}</span>
                        @if ($portion['hint'] !== null)
                            <span class="data-micro block text-ink-faint">{{ $portion['hint'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
            <div class="mt-3 flex items-end gap-3">
                <div class="w-36">
                    <label class="silkscreen whitespace-nowrap" for="consume-amount">Custom ({{ $pantryItem->quantity_unit->shortLabel() }})</label>
                    <input id="consume-amount" type="number" step="any" min="0" max="100000" inputmode="decimal" wire:model="consumeAmount"
                           class="input-well data mt-1.5">
                </div>
                <button type="button" wire:click="consume" wire:loading.attr="disabled" @disabled($outOfStock)
                        class="key keycap-sm px-4 py-3 text-ink-dim disabled:cursor-not-allowed disabled:opacity-40">
                    Consume amount
                </button>
            </div>
            @error('consumeAmount') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror
        </x-app.module>

        {{-- Nutrition for what's currently held — one summary line; the full
             table is a disclosure (it's reference, not a daily read). --}}
        <x-app.module label="Nutrition" padding="px-5 pb-2 pt-4">
            @if ($hasNutrition)
                <p class="data-md mt-2 text-ink">
                    {{ $nutrition->calories === null ? '----' : rtrim(rtrim(number_format($nutrition->calories, 1, '.', ''), '0'), '.') }}<span class="data-micro text-ink-faint"> KCAL</span>
                    <span class="data-sm text-ink-dim">
                        · {{ $nutrition->protein === null ? '--' : rtrim(rtrim(number_format($nutrition->protein, 1, '.', ''), '0'), '.') }}P
                        · {{ $nutrition->carbs === null ? '--' : rtrim(rtrim(number_format($nutrition->carbs, 1, '.', ''), '0'), '.') }}C
                        · {{ $nutrition->fat === null ? '--' : rtrim(rtrim(number_format($nutrition->fat, 1, '.', ''), '0'), '.') }}F
                    </span>
                </p>
                <div x-data="{ open: false }" class="mt-2 border-t border-seam py-2">
                    <button type="button" x-on:click="open = !open" class="keycap-sm flex w-full items-center justify-between text-ink-faint transition hover:text-ink">
                        <span>Full breakdown</span>
                        <span x-text="open ? '−' : '+'" class="data-sm"></span>
                    </button>
                    <div x-show="open" x-cloak class="mt-1 grid grid-cols-2 gap-x-6">
                        @foreach ([
                            ['Sugars', $nutrition->sugars, 'G'],
                            ['Saturated', $nutrition->saturatedFat, 'G'],
                            ['Fibre', $nutrition->fibre, 'G'],
                            ['Salt', $nutrition->salt, 'G'],
                        ] as [$label, $value, $unit])
                            <div class="flex items-baseline justify-between py-1.5">
                                <span class="voice-caption text-ink-dim">{{ $label }}</span>
                                <span class="data-md text-ink">
                                    @if ($value !== null){{ rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') }}<span class="data-micro text-ink-faint"> {{ $unit }}</span>@else <span class="text-ink-faint">----</span>@endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="voice-caption mt-2 pb-2 text-ink-dim">No nutrition data.</p>
            @endif
        </x-app.module>

        {{-- Inventory corrections --}}
        <x-app.module label="Correct stock">
            <div class="mt-3 flex items-end gap-3">
                <div class="w-28">
                    <label class="silkscreen" for="new-quantity">True amount</label>
                    <input id="new-quantity" type="number" step="any" min="0" max="100000" inputmode="decimal" wire:model="newQuantity"
                           class="input-well data mt-1.5">
                </div>
                <button type="button" wire:click="changeQuantity" wire:loading.attr="disabled"
                        class="key keycap-sm px-4 py-3 text-ink-dim">
                    Update
                </button>
            </div>
            @error('newQuantity') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror

            <div class="mt-4 border-t border-seam pt-4">
                @if ($confirmRemove)
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="remove" wire:loading.attr="disabled"
                                class="key keycap border-high bg-high px-4 py-2.5 text-black">
                            Confirm remove
                        </button>
                        <button type="button" wire:click="$set('confirmRemove', false)" class="keycap-sm hit text-ink-dim transition hover:text-ink">Cancel</button>
                    </div>
                @else
                    <button type="button" wire:click="$set('confirmRemove', true)"
                            class="keycap-sm hit text-high transition hover:brightness-125">
                        Remove from pantry
                    </button>
                @endif
            </div>
        </x-app.module>

        {{-- The logged win gets the green stamp — logging is automatic, so the
             stamp carries the way back. A stock correction is a quiet save. --}}
        <div x-show="toast === 'logged'" x-cloak role="status" class="fixed inset-x-0 bottom-28 z-40 mx-auto max-w-md px-5">
            <div class="stamp-in flex items-center justify-between gap-3 rounded-md bg-good px-4 py-3 text-black">
                <span class="flex items-center gap-2.5">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5.5 5.5L20 6.5" /></svg>
                    <span class="keycap">Logged to today</span>
                </span>
                <button type="button"
                        x-on:click="toast = null; clearTimeout(toastT); $wire.undoConsume()"
                        class="keycap hit shrink-0 underline decoration-2 underline-offset-4">
                    Undo
                </button>
            </div>
        </div>
        <x-app.stamp-toast show="toast === 'undone'" tone="neutral">Removed — stock restored</x-app.stamp-toast>
        <x-app.stamp-toast show="toast === 'saved'" tone="neutral">Stock corrected</x-app.stamp-toast>
    </div>
