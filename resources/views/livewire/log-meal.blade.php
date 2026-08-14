<?php

use App\Enums\MealContext;
use App\Models\ConsumptionEvent;
use App\Models\PantryItem;
use App\Services\ConsumptionService;
use App\Services\PortionSuggestionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Log a meal — the capture flow for the consumption ledger (BUILD_PLAN
 * "Reframe, Aug 2026"; brief §8.4). Three contexts, one ledger:
 *
 *  - Packaged      -> the Scan flow (linked, not duplicated here).
 *  - Home-cooked   -> compose from pantry components, portions via
 *                     PortionSuggestionService, totals summed deterministically.
 *  - Eating out    -> name + optional estimated figures; anything unknown stays
 *                     an honest null. Completeness beats precision — a coarse
 *                     entry beats a gap.
 *
 * "Usuals" are read straight from the user's own history (recent meal events),
 * not a separate table: eating-out usuals re-log in one tap; home-cooked usuals
 * prefill the compose screen for confirmation (stock may have changed).
 *
 * THIN (brief §4.1): all writes go through ConsumptionService; portion
 * quantities are re-derived server-side from chip choices, never trusted from
 * the client.
 */
new #[Layout('components.layouts.app', ['title' => 'Log'])] class extends Component
{
    /** context | home | out | done */
    public string $step = 'context';

    // Home-cooked compose: [pantryItemId => ['choice' => int|'custom', 'custom' => string]]
    /** @var array<int, array{choice: int|string, custom: string}> */
    public array $components = [];

    public string $mealName = '';

    public string $filter = '';

    // Eating out
    public string $outName = '';

    public string $outCalories = '';

    public string $outProtein = '';

    public string $outCarbs = '';

    public string $outFat = '';

    // Done
    public string $loggedName = '';

    public function chooseHomeCooked(): void
    {
        $this->step = 'home';
    }

    public function chooseEatingOut(): void
    {
        $this->step = 'out';
    }

    public function addComponent(int $itemId): void
    {
        $item = $this->ownedItem($itemId);

        if ($item !== null && ! isset($this->components[$itemId])) {
            $this->components[$itemId] = ['choice' => 0, 'custom' => ''];
        }
    }

    public function removeComponent(int $itemId): void
    {
        unset($this->components[$itemId]);
    }

    public function logHomeCooked(ConsumptionService $service, PortionSuggestionService $portions): void
    {
        $this->resetValidation();

        $lines = [];

        foreach ($this->components as $itemId => $state) {
            $item = $this->ownedItem((int) $itemId);

            if ($item === null) {
                continue;
            }

            [$quantity, $label] = $this->resolveChoice($item, $state, $portions);

            if ($quantity === null) {
                $this->addError('components', 'Give "'.$item->canonicalProduct->name.'" a valid amount.');

                return;
            }

            $lines[] = ['item' => $item, 'quantity' => $quantity, 'portion_label' => $label];
        }

        if ($lines === []) {
            $this->addError('components', 'Add at least one component from your pantry.');

            return;
        }

        $event = $service->logHomeCookedMeal(Auth::user(), $this->mealName, $lines);

        $this->finish($event->name);
    }

    public function logOut(ConsumptionService $service): void
    {
        $data = $this->validate([
            'outName' => ['required', 'string', 'max:120'],
            'outCalories' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'outProtein' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'outCarbs' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'outFat' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        $figure = static fn (string $v): ?float => $v === '' ? null : (float) $v;

        $event = $service->logEatingOut(Auth::user(), $data['outName'], [
            'calories' => $figure($this->outCalories),
            'protein' => $figure($this->outProtein),
            'carbs' => $figure($this->outCarbs),
            'fat' => $figure($this->outFat),
        ]);

        $this->finish($event->name);
    }

    /** One-tap re-log of an eating-out usual; home-cooked usuals prefill instead. */
    public function useUsual(ConsumptionService $service, int $eventId): void
    {
        $usual = Auth::user()->consumptionEvents()->with('items')->find($eventId);

        if ($usual === null) {
            return;
        }

        if ($usual->context === MealContext::EatingOut) {
            $event = $service->logEatingOut(Auth::user(), (string) $usual->name, [
                'calories' => $usual->calories !== null ? (float) $usual->calories : null,
                'protein' => $usual->protein !== null ? (float) $usual->protein : null,
                'carbs' => $usual->carbs !== null ? (float) $usual->carbs : null,
                'fat' => $usual->fat !== null ? (float) $usual->fat : null,
                'sugars' => $usual->sugars !== null ? (float) $usual->sugars : null,
                'saturated_fat' => $usual->saturated_fat !== null ? (float) $usual->saturated_fat : null,
                'fibre' => $usual->fibre !== null ? (float) $usual->fibre : null,
                'salt' => $usual->salt !== null ? (float) $usual->salt : null,
            ]);

            $this->finish($event->name);

            return;
        }

        // Home-cooked: prefill the compose screen for confirmation — stock and
        // portions may have changed since last time, so the user re-confirms.
        $this->mealName = (string) $usual->name;
        $this->components = [];

        foreach ($usual->items as $line) {
            $item = PantryItem::query()
                ->where('user_id', Auth::id())
                ->where('canonical_product_id', $line->canonical_product_id)
                ->first();

            if ($item !== null) {
                $this->components[$item->id] = ['choice' => 'custom', 'custom' => rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.')];
            }
        }

        $this->step = 'home';
    }

    public function startOver(): void
    {
        $this->reset(['step', 'components', 'mealName', 'filter', 'outName', 'outCalories', 'outProtein', 'outCarbs', 'outFat', 'loggedName']);
        $this->resetValidation();
    }

    private function finish(string $name): void
    {
        $this->loggedName = $name;
        $this->step = 'done';
    }

    /**
     * Resolve a component's chip/custom choice to a quantity + label, deriving
     * chips server-side so the client never supplies a chip quantity.
     *
     * @param  array{choice: int|string, custom: string}  $state
     * @return array{0: float|null, 1: string|null}
     */
    private function resolveChoice(PantryItem $item, array $state, PortionSuggestionService $portions): array
    {
        if ($state['choice'] === 'custom') {
            $custom = trim($state['custom']);

            if ($custom === '' || ! is_numeric($custom) || (float) $custom <= 0 || (float) $custom > 100000) {
                return [null, null];
            }

            return [(float) $custom, null];
        }

        $options = $portions->suggestionsFor($item, Auth::user());
        $chosen = $options[(int) $state['choice']] ?? null;

        return $chosen === null ? [null, null] : [$chosen['quantity'], $chosen['record']];
    }

    private function ownedItem(int $itemId): ?PantryItem
    {
        $item = PantryItem::with('canonicalProduct')->find($itemId);

        return $item !== null && $item->user_id === Auth::id() ? $item : null;
    }

    public function with(PortionSuggestionService $portions): array
    {
        $pantryItems = PantryItem::with('canonicalProduct')
            ->where('user_id', Auth::id())
            ->where('current_quantity', '>', 0)
            ->get()
            ->sortBy(fn (PantryItem $i) => mb_strtolower($i->canonicalProduct->name))
            ->values();

        if (trim($this->filter) !== '') {
            $needle = mb_strtolower(trim($this->filter));
            $pantryItems = $pantryItems->filter(
                fn (PantryItem $i) => str_contains(mb_strtolower($i->canonicalProduct->brand.' '.$i->canonicalProduct->name), $needle)
            )->values();
        }

        // Usuals: the user's recent meals, one per name, newest first.
        $usuals = Auth::user()->consumptionEvents()
            ->where('type', 'meal')
            ->orderByDesc('consumed_at')
            ->limit(30)
            ->get()
            ->unique(fn (ConsumptionEvent $e) => mb_strtolower((string) $e->name))
            ->take(6)
            ->values();

        // Chip options per selected component (re-derived every render).
        $selected = [];

        foreach ($this->components as $itemId => $state) {
            $item = $this->ownedItem((int) $itemId);

            if ($item !== null) {
                $selected[] = [
                    'item' => $item,
                    'state' => $state,
                    'options' => $portions->suggestionsFor($item, Auth::user()),
                ];
            }
        }

        return [
            'pantryItems' => $pantryItems,
            'usuals' => $usuals,
            'selected' => $selected,
        ];
    }
}; ?>

    <div class="space-y-5">

        <a href="{{ route('eat') }}" class="keycap-sm inline-flex items-center gap-1.5 px-1 text-ink-dim transition hover:text-ink">
            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
            Eat
        </a>

        {{-- STEP — context: where did this meal come from? --------------------}}
        @if ($step === 'context')
            <x-app.module label="Log a meal">
                <p class="voice-caption mt-2 text-ink-dim">Every meal counts towards your picture — even rough ones.</p>

                <div class="mt-4 space-y-2">
                    <x-app.console-key primary wire:click="chooseHomeCooked">Home-cooked</x-app.console-key>
                    <x-app.console-key wire:click="chooseEatingOut">Eating out</x-app.console-key>
                    <x-app.console-key :href="route('scan')">Packaged — scan it</x-app.console-key>
                </div>
            </x-app.module>

            @if ($usuals->isNotEmpty())
                <x-app.module label="Your usuals">
                    <p class="voice-caption mt-2 text-ink-dim">Eating-out usuals log in one tap; home-cooked ones prefill for you to confirm.</p>
                    <div class="mt-3 space-y-2">
                        @foreach ($usuals as $usual)
                            <button type="button" wire:click="useUsual({{ $usual->id }})" wire:loading.attr="disabled"
                                    class="key keycap-sm flex w-full items-center justify-between px-4 py-3 text-left">
                                <span class="min-w-0 truncate text-ink">{{ $usual->name }}</span>
                                <span class="data-sm ml-3 shrink-0 text-ink-faint">
                                    {{ $usual->context === \App\Enums\MealContext::EatingOut ? 'OUT' : 'HOME' }}
                                    · {{ $usual->calories === null ? '----' : ($usual->estimated ? '~' : '').number_format((float) $usual->calories, 0) }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                </x-app.module>
            @endif
        @endif

        {{-- STEP — home-cooked: compose from pantry ---------------------------}}
        @if ($step === 'home')
            <x-app.module label="Home-cooked — what went in?">
                <div class="mt-3">
                    <label class="silkscreen" for="meal-name">Meal name</label>
                    <input id="meal-name" type="text" maxlength="120" wire:model="mealName" placeholder="e.g. Chicken curry"
                           class="input-well mt-1.5 w-full">
                </div>

                @if ($selected !== [])
                    <div class="mt-4 space-y-3">
                        @foreach ($selected as $row)
                            <div class="rounded border border-seam bg-plate-well p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="data-md min-w-0 truncate text-ink uppercase">{{ $row['item']->canonicalProduct->name }}</p>
                                    <button type="button" wire:click="removeComponent({{ $row['item']->id }})" aria-label="Remove component"
                                            class="hit flex size-7 shrink-0 items-center justify-center text-ink-faint transition hover:text-high">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                                <div class="mt-2 flex flex-wrap items-end gap-2">
                                    <select wire:model.live="components.{{ $row['item']->id }}.choice" class="input-well data flex-1"
                                            aria-label="Portion for {{ $row['item']->canonicalProduct->name }}">
                                        @foreach ($row['options'] as $i => $option)
                                            <option value="{{ $i }}">{{ $option['label'] }}{{ $option['hint'] !== null ? ' · '.$option['hint'] : '' }}</option>
                                        @endforeach
                                        <option value="custom">Custom ({{ $row['item']->quantity_unit->shortLabel() }})</option>
                                    </select>
                                    @if (($row['state']['choice'] ?? 0) === 'custom')
                                        <input type="number" step="any" min="0" max="100000" inputmode="decimal"
                                               wire:model="components.{{ $row['item']->id }}.custom"
                                               class="input-well data w-24" aria-label="Custom amount">
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @error('components') <p class="mt-2 text-xs text-high">{{ $message }}</p> @enderror

                <div class="mt-4">
                    <label class="silkscreen" for="component-filter">Add from your pantry</label>
                    <input id="component-filter" type="text" wire:model.live.debounce.300ms="filter" placeholder="Filter…"
                           class="input-well mt-1.5 w-full">
                    <div class="mt-2 max-h-64 space-y-1 overflow-y-auto">
                        @forelse ($pantryItems as $item)
                            @continue(isset($components[$item->id]))
                            <button type="button" wire:click="addComponent({{ $item->id }})"
                                    class="key keycap-sm flex w-full items-center justify-between px-3 py-2.5 text-left">
                                <span class="min-w-0 truncate text-ink-dim">{{ $item->canonicalProduct->brand }} {{ $item->canonicalProduct->name }}</span>
                                <span class="data-sm ml-2 shrink-0 text-ink-faint">+</span>
                            </button>
                        @empty
                            <p class="voice-caption py-2 text-ink-dim">Nothing in stock{{ trim($filter) !== '' ? ' matching that' : '' }} — scan or add products first.</p>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    <x-app.console-key primary wire:click="logHomeCooked" wire:loading.attr="disabled">Log this meal</x-app.console-key>
                    <x-app.console-key wire:click="startOver">Back</x-app.console-key>
                </div>
            </x-app.module>
        @endif

        {{-- STEP — eating out: rough is fine ---------------------------------}}
        @if ($step === 'out')
            <x-app.module label="Eating out — rough is fine">
                <p class="voice-caption mt-2 text-ink-dim">Name it, add any figures you know. Unknowns stay unknown — they're never faked.</p>

                <div class="mt-3">
                    <label class="silkscreen" for="out-name">What was it?</label>
                    <input id="out-name" type="text" maxlength="120" wire:model="outName" placeholder="e.g. Katsu curry at Wagamama"
                           class="input-well mt-1.5 w-full">
                    @error('outName') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3">
                    @foreach ([
                        ['outCalories', 'Calories (kcal)'],
                        ['outProtein', 'Protein (g)'],
                        ['outCarbs', 'Carbs (g)'],
                        ['outFat', 'Fat (g)'],
                    ] as [$field, $label])
                        <div>
                            <label class="silkscreen" for="{{ $field }}">{{ $label }}</label>
                            <input id="{{ $field }}" type="number" step="any" min="0" max="99999" inputmode="decimal"
                                   wire:model="{{ $field }}" placeholder="Optional" class="input-well data mt-1.5 w-full">
                            @error($field) <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 space-y-2">
                    <x-app.console-key primary wire:click="logOut" wire:loading.attr="disabled">Log it</x-app.console-key>
                    <x-app.console-key wire:click="startOver">Back</x-app.console-key>
                </div>
            </x-app.module>
        @endif

        {{-- STEP — done -------------------------------------------------------}}
        @if ($step === 'done')
            <x-app.module label="Logged">
                <p class="voice-display mt-4 text-[2.2rem]">On the<br>record</p>
                <p class="voice-caption mt-2 text-ink-dim">{{ $loggedName }}</p>

                <div class="mt-5 space-y-2">
                    <x-app.console-key primary wire:click="startOver">Log another</x-app.console-key>
                    <x-app.console-key :href="route('eat')">See today's log</x-app.console-key>
                </div>
            </x-app.module>
        @endif
    </div>
