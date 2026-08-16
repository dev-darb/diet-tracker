<?php

use App\Enums\MealContext;
use App\Models\NutritionEstimate;
use App\Nutrition\Estimation\EstimationBasis;
use App\Nutrition\Estimation\EstimationReason;
use App\Nutrition\Estimation\EstimationRequest;
use App\Nutrition\NutrientOrigin;
use App\Nutrition\NutrientOrigins;
use App\Services\NutritionEstimationService;
use App\ValueObjects\NutrientValues;
use App\Enums\ScanCaptureStatus;
use App\Models\ConsumptionEvent;
use App\Models\PantryItem;
use App\Models\ScanCapture;
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

    /** The scan capture this meal came from (?capture= bridge), if any. */
    public ?int $captureId = null;

    /** The chef suggestion being cooked (?chef= bridge), if any. */
    public ?int $chefId = null;

    /** The scan reading's summary line, shown above the prefilled flow. */
    public ?string $photoNote = null;

    // Home-cooked compose: [pantryItemId => ['choice' => int|'custom', 'custom' => string]]
    /** @var array<int, array{choice: int|string, custom: string}> */
    public array $components = [];

    public string $mealName = '';

    public string $filter = '';

    // Eating out
    public string $outName = '';

    public string $outVenue = '';

    public string $outCalories = '';

    public string $outProtein = '';

    public string $outCarbs = '';

    public string $outFat = '';

    /** @var array<string, float|null> estimator-stated sugars/sat-fat/fibre/salt, carried into the event unedited */
    public array $outSecondary = [];

    /** Set after a successful AI estimate: the stated basis + confidence. */
    public ?string $estimateBasis = null;

    public ?int $estimateConfidence = null;

    public bool $estimateFailed = false;

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

        $this->finish($event->name, $event);
    }

    /** The estimate record behind the prefilled figures, so its working can be shown. */
    public ?int $estimateId = null;

    /** @var array<int, string> the model's reasoning, kept for the disclosure */
    public array $estimateWorking = [];

    /**
     * Ask for a reasoned estimate of the dish (the user should never NEED to know
     * the figures). This is a bounded ask, not a blank cheque: the food is named,
     * the nutrients are named, and the reason estimation is permitted at all is
     * recorded — there is no source record for a dish someone cooked in a
     * restaurant kitchen.
     *
     * What comes back has already been through the guardrails. If the reasoning
     * was missing, the confidence too low, or the figures did not hold together,
     * nothing arrives and the user types what they know instead.
     */
    public function estimateOut(NutritionEstimationService $estimates): void
    {
        $this->validate(['outName' => ['required', 'string', 'max:120']]);
        $this->estimateFailed = false;

        $venue = trim($this->outVenue) ?: null;

        $outcome = $estimates->estimate(
            EstimationRequest::for(
                subject: $this->outName,
                reason: EstimationReason::NoSourceRecord,
                basis: EstimationBasis::WholeItem,
                // The full macro set: the moderation and fibre pillars need the
                // secondary figures, and dropping a nutrient the model reasoned
                // about would manufacture an unknown out of a known.
                nutrients: NutrientValues::MACRO_KEYS,
                context: array_filter(['venue' => $venue, 'meal' => 'eaten out']),
            ),
            Auth::user(),
        );

        if ($outcome === null || ! $outcome->isAccepted()) {
            $this->estimateFailed = true;

            return;
        }

        $values = $outcome->values();
        $fill = static fn (?float $v): string => $v === null ? '' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');

        $this->outCalories = $fill($values['calories'] ?? null);
        $this->outProtein = $fill($values['protein'] ?? null);
        $this->outCarbs = $fill($values['carbs'] ?? null);
        $this->outFat = $fill($values['fat'] ?? null);
        // Sugars, saturates, fibre and salt stay off the edit surface but ride
        // into the event: the moderation and fibre pillars need them.
        $this->outSecondary = array_intersect_key($values, array_flip(['sugars', 'saturated_fat', 'fibre', 'salt']));

        $this->estimateId = $outcome->record->id;
        $this->estimateWorking = $outcome->record->workingLines();
        $this->estimateBasis = $outcome->record->reference;
        $this->estimateConfidence = (int) round(($outcome->record->confidence ?? 0) * 100);
    }

    /**
     * The SCAN → meal bridge (unified capture, Aug 2026). A capture the
     * scanner triaged as a prepared meal arrives here via ?capture=; its
     * stored interpreter reading (taken at capture time, while the photo was
     * still readable) prefills both contexts — dish name, matched pantry
     * components, the also-seen list. The user still chooses home-cooked vs
     * eating out and confirms everything; nothing logs by itself.
     */
    public function mount(): void
    {
        $this->mountChef();

        $captureId = (int) request()->query('capture', 0);

        if ($captureId <= 0) {
            return;
        }

        $capture = ScanCapture::query()
            ->whereKey($captureId)
            ->where('user_id', Auth::id())
            ->where('status', ScanCaptureStatus::Meal)
            ->whereNull('consumption_event_id')
            ->first();

        if ($capture === null) {
            return;
        }

        $this->captureId = $capture->id;
        $reading = $capture->meal_reading ?? [];
        $dish = $capture->dish_name ?? ($reading['dish_name'] ?? null);

        $this->mealName = $dish ?? '';
        $this->outName = $dish ?? '';

        foreach (($reading['pantry_item_ids'] ?? []) as $id) {
            if ($this->ownedItem((int) $id) !== null) {
                $this->components[(int) $id] = ['choice' => 0, 'custom' => ''];
            }
        }

        $parts = [$dish !== null ? 'From your scan: '.$dish : 'From your scan'];
        if (($matched = count($this->components)) > 0) {
            $parts[] = $matched.' pantry match'.($matched === 1 ? '' : 'es');
        }
        if (($alsoSeen = (array) ($reading['also_seen'] ?? [])) !== []) {
            $parts[] = 'also saw: '.implode(', ', $alsoSeen).' (not in your pantry)';
        }

        $this->photoNote = implode(' · ', $parts);
    }

    /**
     * The CHEF → meal bridge (tranche 4): "Cooked this" on the resident chef
     * card arrives here via ?chef=, jumping straight into home-cooked compose
     * with the dish name and the in-stock ingredients preselected. The user
     * confirms portions; the deterministic maths stay untouched.
     */
    private function mountChef(): void
    {
        $chefId = (int) request()->query('chef', 0);

        if ($chefId <= 0) {
            return;
        }

        $suggestion = \App\Models\ChefSuggestion::query()
            ->whereKey($chefId)
            ->where('user_id', Auth::id())
            ->whereNull('consumption_event_id')
            ->first();

        if ($suggestion === null) {
            return;
        }

        $this->chefId = $suggestion->id;
        $this->mealName = $suggestion->title;
        $this->step = 'home';

        foreach ($suggestion->inStockItemIds() as $id) {
            if ($this->ownedItem((int) $id) !== null) {
                $this->components[(int) $id] = ['choice' => 0, 'custom' => ''];
            }
        }

        $this->photoNote = 'From the chef: '.$suggestion->title.' — confirm what actually went in.';
    }

    public function logOut(ConsumptionService $service, NutritionEstimationService $estimates): void
    {
        $data = $this->validate([
            'outName' => ['required', 'string', 'max:120'],
            'outVenue' => ['nullable', 'string', 'max:120'],
            'outCalories' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'outProtein' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'outCarbs' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'outFat' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        $figure = static fn (string $v): ?float => $v === '' ? null : (float) $v;

        $figures = [
            'calories' => $figure($this->outCalories),
            'protein' => $figure($this->outProtein),
            'carbs' => $figure($this->outCarbs),
            'fat' => $figure($this->outFat),
            // The estimate's secondary figures (null when entered manually).
            'sugars' => $this->outSecondary['sugars'] ?? null,
            'saturated_fat' => $this->outSecondary['saturated_fat'] ?? null,
            'fibre' => $this->outSecondary['fibre'] ?? null,
            'salt' => $this->outSecondary['salt'] ?? null,
        ];

        $event = $service->logEatingOut(
            Auth::user(),
            $data['outName'],
            $figures,
            venue: trim($this->outVenue) ?: null,
            origins: $this->originsFor($figures),
        );

        // Close the link from the estimate to what it ended up on, so the working
        // stays reachable from the logged meal months later.
        if ($this->estimateRecord() !== null) {
            $estimates->attach($this->estimateRecord(), $event);
        }

        $this->finish($event->name, $event);
    }

    /**
     * Mark the figures that came from the estimate, and only those.
     *
     * A figure the user typed over is theirs, not the model's — comparing what
     * is being logged against what the estimate proposed is what keeps the
     * marker honest when someone corrects a number by hand.
     *
     * @param  array<string, float|null>  $figures
     */
    private function originsFor(array $figures): NutrientOrigins
    {
        $record = $this->estimateRecord();

        if ($record === null) {
            return NutrientOrigins::none();
        }

        $estimated = [];

        foreach ((array) ($record->values ?? []) as $key => $value) {
            $logged = $figures[$key] ?? null;

            if ($logged !== null && abs((float) $logged - (float) $value) < 0.05) {
                $estimated[] = $key;
            }
        }

        return NutrientOrigins::none()->mark($estimated, NutrientOrigin::Estimated, (string) $record->id);
    }

    private function estimateRecord(): ?NutritionEstimate
    {
        return $this->estimateId === null
            ? null
            : NutritionEstimate::query()
                ->whereKey($this->estimateId)
                ->where('user_id', Auth::id())
                ->first();
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
            ], venue: $usual->venue);

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
        $this->reset(['step', 'components', 'mealName', 'filter', 'outName', 'outVenue', 'outCalories', 'outProtein', 'outCarbs', 'outFat', 'outSecondary', 'estimateBasis', 'estimateConfidence', 'estimateFailed', 'estimateId', 'estimateWorking', 'captureId', 'chefId', 'photoNote', 'loggedName']);
        $this->resetValidation();
    }

    private function finish(string $name, ?ConsumptionEvent $event = null): void
    {
        // Close the loop with the originating scan capture, if any — its card
        // on the scan stack flips to LOGGED TO TODAY.
        if ($event !== null && $this->captureId !== null) {
            ScanCapture::query()
                ->whereKey($this->captureId)
                ->where('user_id', Auth::id())
                ->where('status', ScanCaptureStatus::Meal)
                ->update(['consumption_event_id' => $event->id]);
        }

        // And with the chef: the resident card flips to COOKED · LOGGED.
        if ($event !== null && $this->chefId !== null) {
            \App\Models\ChefSuggestion::query()
                ->whereKey($this->chefId)
                ->where('user_id', Auth::id())
                ->whereNull('consumption_event_id')
                ->update(['consumption_event_id' => $event->id]);
        }

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

    public function with(PortionSuggestionService $portions, NutritionEstimationService $estimates): array
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
                fn (PantryItem $i) => str_contains(mb_strtolower($i->canonicalProduct->displayName().' '.$i->canonicalProduct->name), $needle)
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
            'estimatorAvailable' => $estimates->available(),
        ];
    }
}; ?>

    <div class="space-y-5">

        <a href="{{ route('eat') }}" wire:navigate class="keycap-sm inline-flex items-center gap-1.5 px-1 text-ink-dim transition hover:text-ink">
            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
            Eat
        </a>

        {{-- STEP — context. Recognition beats the form: usuals first — the
             repeat meal is one tap; the taxonomy is for new ones. --}}
        @if ($step === 'context')
            @if ($usuals->isNotEmpty())
                <section>
                <h2 class="silkscreen border-b border-seam pb-2">Your usuals</h2>
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
                </section>
            @endif

            <section>
                <h2 class="silkscreen border-b border-seam pb-2">{{ $usuals->isNotEmpty() ? 'Something new' : 'Log a meal' }}</h2>
                <div class="mt-4 space-y-2">
                    <x-app.console-key primary wire:click="chooseHomeCooked">
                        <span class="flex items-center justify-center gap-2"><x-app.icon name="pan" />Home-cooked</span>
                    </x-app.console-key>
                    <x-app.console-key wire:click="chooseEatingOut">
                        <span class="flex items-center justify-center gap-2"><x-app.icon name="storefront" />Eating out</span>
                    </x-app.console-key>
                    <x-app.console-key :href="route('scan')">
                        <span class="flex items-center justify-center gap-2"><x-app.icon name="barcode" />Packaged — scan it</span>
                    </x-app.console-key>
                </div>
            </section>
        @endif

        {{-- STEP — home-cooked: compose from pantry ---------------------------}}
        @if ($step === 'home')
            <section>
                <h2 class="silkscreen border-b border-seam pb-2">Home-cooked — what went in?</h2>
                @if ($photoNote !== null)
                    {{-- The scan's reading — dish, matched components, also-seen. --}}
                    <p class="mt-3 rounded bg-plate-well px-3 py-2 text-xs text-ink-dim">{{ $photoNote }}</p>
                @else
                    {{-- The camera lives in ONE place: point the scanner at the
                         plate and it lands here prefilled (unified capture). --}}
                    <p class="mt-3 text-xs text-ink-dim">
                        Got it in front of you?
                        <a href="{{ route('scan') }}" wire:navigate class="text-ink-dim underline decoration-seam-strong underline-offset-2 transition hover:text-ink">Point the scanner at it</a>
                        and it lands here prefilled.
                    </p>
                @endif

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
                            {{-- The name is spoken voice, never keycap engraving
                                 (Two Voices, One Speaker) — the key material
                                 stays, the label doesn't shout. --}}
                            <button type="button" wire:click="addComponent({{ $item->id }})"
                                    class="key flex w-full items-center gap-2.5 px-2.5 py-2 text-left">
                                {{-- Ingredients look like food (One Row Molecule):
                                     the product's own photo, or a quiet monogram. --}}
                                @if ($item->canonicalProduct->primary_image_path)
                                    <img src="{{ $item->canonicalProduct->primary_image_path }}" alt="" loading="lazy"
                                         class="size-7 shrink-0 rounded bg-plate-well object-cover">
                                @else
                                    <span class="flex size-7 shrink-0 items-center justify-center rounded bg-plate-well">
                                        <span class="data-micro text-ink-faint">{{ mb_strtoupper(mb_substr($item->canonicalProduct->name, 0, 1)) }}</span>
                                    </span>
                                @endif
                                <span class="voice-caption min-w-0 flex-1 truncate text-ink-dim">{{ $item->canonicalProduct->displayName() }}</span>
                                <span class="data-sm shrink-0 text-ink-faint">+</span>
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
            </section>
        @endif

        {{-- STEP — eating out: we estimate, you confirm -----------------------}}
        @if ($step === 'out')
            <section>
                <h2 class="silkscreen border-b border-seam pb-2">Eating out</h2>

                @if ($photoNote !== null)
                    {{-- The scan's reading of the dish. --}}
                    <p class="mt-3 rounded bg-plate-well px-3 py-2 text-xs text-ink-dim">{{ $photoNote }}</p>
                @endif

                <div class="mt-3">
                    <label class="silkscreen" for="out-name">What was it?</label>
                    <input id="out-name" type="text" maxlength="120" wire:model="outName" placeholder="e.g. Chicken katsu curry"
                           class="input-well mt-1.5 w-full">
                    @error('outName') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror
                </div>

                <div class="mt-3">
                    <label class="silkscreen" for="out-venue">Where? <span class="text-ink-faint">(optional — chains estimate best)</span></label>
                    <input id="out-venue" type="text" maxlength="120" wire:model="outVenue" placeholder="e.g. Wagamama"
                           class="input-well mt-1.5 w-full">
                    @error('outVenue') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror
                </div>

                @if ($estimatorAvailable)
                    <div class="mt-3">
                        <x-app.console-key wire:click="estimateOut" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="estimateOut">Estimate the figures</span>
                            <span wire:loading wire:target="estimateOut">Estimating…</span>
                        </x-app.console-key>
                    </div>

                    @if ($estimateFailed)
                        <p class="mt-2 rounded bg-plate-well px-3 py-2 text-xs text-ink-dim">
                            Couldn't estimate that one — log it without figures, or fill in anything you know.
                        </p>
                    @endif

                    @if ($estimateBasis !== null)
                        {{-- The estimate says what it is and what it was based
                             on, with the reasoning one tap away. Quiet by
                             default: a marker, not a warning — the figure is
                             usable, it just is not a label reading. --}}
                        <div class="mt-2 rounded bg-plate-well px-3 py-2" x-data="{ working: false }">
                            <p class="text-xs text-ink-dim">
                                <span class="silkscreen">~ Estimate</span> · {{ $estimateBasis }}
                                @if ($estimateConfidence !== null)
                                    <span class="data-sm text-ink-faint">· {{ $estimateConfidence }}% confident</span>
                                @endif
                            </p>
                            @if ($estimateWorking !== [])
                                <button type="button" x-on:click="working = !working"
                                        class="keycap-sm hit mt-1.5 flex items-center gap-1.5 text-ink-faint transition hover:text-ink">
                                    <span x-text="working ? 'Hide how' : 'How was this worked out?'"></span>
                                    <span class="data-sm" x-text="working ? '−' : '+'"></span>
                                </button>
                                <ul x-show="working" x-cloak class="mt-2 space-y-1 border-t border-seam pt-2">
                                    @foreach ($estimateWorking as $line)
                                        <li class="voice-micro flex gap-2 text-ink-dim">
                                            <span class="text-ink-faint" aria-hidden="true">·</span>
                                            <span>{{ $line }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                @endif

                {{-- Figures: pre-filled by the estimate, always editable, always optional. --}}
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
            </section>
        @endif

        {{-- STEP — done -------------------------------------------------------}}
        @if ($step === 'done')
            <section>
                <h2 class="silkscreen border-b border-seam pb-2">Logged</h2>
                <p class="voice-display mt-4 text-[2.6rem]">On the<br>record</p>
                <p class="voice-caption mt-2 text-ink-dim">{{ $loggedName }}</p>

                <div class="mt-5 space-y-2">
                    <x-app.console-key primary wire:click="startOver">Log another</x-app.console-key>
                    <x-app.console-key :href="route('eat')">See today's log</x-app.console-key>
                </div>
            </section>
        @endif
    </div>
