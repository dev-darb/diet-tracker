<?php

namespace App\Services;

use App\Enums\ConsumptionType;
use App\Enums\MealContext;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\PantryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Nutrition\NutrientOrigins;
use App\ValueObjects\NutrientValues;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The consumption service (BUILD_PLAN §6 J4.1; brief §8.1–§8.7, ideas #5/#6).
 *
 * Orchestrates "I ate this" end-to-end inside ONE database transaction:
 *   1. select the product version in effect (same rule as PantryNutritionService),
 *   2. compute the nutrient contribution DETERMINISTICALLY via NutritionCalculator,
 *   3. write a `consumption_event` (+ `consumption_item`) with those figures
 *      SNAPSHOTTED — copied in, never referenced — so a later reformulation can
 *      never change a historical day (idea #6, brief §10.3/§12),
 *   4. deduct the pantry through PantryService, linking the ledger row to the event.
 *
 * This class never does nutrient arithmetic inline (brief §8.9): every figure
 * comes from {@see NutritionCalculator}. Unknown nutrients propagate as null and
 * are never fabricated as 0 (brief §2.1).
 *
 * ## Unit model
 * Consumption is expressed in the pantry item's own unit (g / ml / unit /
 * portion / pack) — which is exactly what §8.3's "all / half / custom" amounts
 * map to (half a wrap = 0.5 of a unit item, 150g chicken = a gram item, …).
 * A `$unit` may be passed for an explicit record but must match the item's unit;
 * cross-unit consumption (deducting grams from a unit-stored item) is deferred
 * to the meal builder (Milestone 5) where serving sizes are handled per line.
 *
 * ## Reversal (brief §8.6)
 * The ledger is append-only, so edit/delete never mutate the original `consume`
 * row. Instead PantryService::adjust writes a compensating `correction` sized
 * from the event's live net ledger effect, so the cached balance stays correct
 * and PantryService::reconcile keeps holding. On edit the snapshot is recomputed
 * (against the version originally eaten, preserving history); on delete the full
 * deduction is restored and the event removed.
 */
class ConsumptionService
{
    public function __construct(
        private readonly NutritionCalculator $calculator,
        private readonly PantryNutritionService $pantryNutrition,
        private readonly PantryService $pantry,
    ) {}

    /**
     * Log eating `$quantity $unit` of a pantry item (brief §8.1–§8.3, §8.7).
     *
     * @param  QuantityUnit|null  $unit  defaults to the item's own unit; when
     *                                   given it must equal the item's unit.
     * @param  string|null  $portionLabel  natural-language wording of the portion
     *                                     ("one (48 g)") snapshotted for history
     *                                     display; presentation only, never maths.
     */
    public function consumePantryItem(
        User $user,
        PantryItem $item,
        float $quantity,
        ?QuantityUnit $unit = null,
        ?Carbon $consumedAt = null,
        ?string $portionLabel = null,
    ): ConsumptionEvent {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A positive quantity is required to consume.');
        }

        $unit = $this->resolveUnit($item, $unit);
        $consumedAt ??= now();

        return DB::transaction(function () use ($user, $item, $quantity, $unit, $consumedAt, $portionLabel) {
            $product = $item->canonicalProduct;
            $version = $this->pantryNutrition->currentVersion($product);
            $contribution = $this->contributionFor($product, $version, $quantity, $unit);

            $event = ConsumptionEvent::create([
                'user_id' => $user->id,
                'type' => ConsumptionType::Single,
                'context' => MealContext::Pantry,
                'name' => $this->productLabel($product),
                'consumed_at' => $consumedAt,
                ...$contribution->toArray(2),
            ]);

            $event->items()->create([
                'canonical_product_id' => $product->id,
                'product_version_id' => $version?->id,
                'quantity' => $quantity,
                'unit' => $unit,
                'portion_label' => $portionLabel,
                ...$contribution->toArray(2),
            ]);

            // Deterministic inventory deduction, linked back to this event.
            $this->pantry->consume($item, $quantity, $event->id, $consumedAt);

            return $event;
        });
    }

    /**
     * Log a home-cooked meal composed of pantry components (Phase A of the
     * capture flow; brief §8.4). Each line is consumed in ITS OWN pantry item's
     * unit — the same rule as single consumption — and the meal's totals are the
     * calculator's sum of the per-line contributions (nulls propagate honestly).
     * Every line's deduction links back to the one event, so delete restores
     * every component.
     *
     * @param  list<array{item: PantryItem, quantity: float, portion_label?: string|null}>  $lines
     */
    public function logHomeCookedMeal(
        User $user,
        string $name,
        array $lines,
        ?Carbon $consumedAt = null,
    ): ConsumptionEvent {
        if ($lines === []) {
            throw new InvalidArgumentException('A meal needs at least one component.');
        }

        foreach ($lines as $line) {
            if (($line['quantity'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Every meal component needs a positive quantity.');
            }

            if ($line['item']->user_id !== $user->id) {
                throw new InvalidArgumentException('Meal components must come from your own pantry.');
            }
        }

        $consumedAt ??= now();
        $name = trim($name) !== '' ? trim($name) : 'Home-cooked meal';

        return DB::transaction(function () use ($user, $name, $lines, $consumedAt) {
            $prepared = [];

            foreach ($lines as $line) {
                $item = $line['item'];
                $product = $item->canonicalProduct;
                $version = $this->pantryNutrition->currentVersion($product);

                $prepared[] = [
                    'item' => $item,
                    'product' => $product,
                    'version' => $version,
                    'quantity' => (float) $line['quantity'],
                    'portion_label' => $line['portion_label'] ?? null,
                    'contribution' => $this->contributionFor($product, $version, (float) $line['quantity'], $item->quantity_unit),
                ];
            }

            $totals = $this->calculator->sum(array_column($prepared, 'contribution'));

            $event = ConsumptionEvent::create([
                'user_id' => $user->id,
                'type' => ConsumptionType::Meal,
                'context' => MealContext::HomeCooked,
                'estimated' => false,
                'name' => $name,
                'consumed_at' => $consumedAt,
                ...$totals->toArray(2),
            ]);

            foreach ($prepared as $line) {
                $event->items()->create([
                    'canonical_product_id' => $line['product']->id,
                    'product_version_id' => $line['version']?->id,
                    'quantity' => $line['quantity'],
                    'unit' => $line['item']->quantity_unit,
                    'portion_label' => $line['portion_label'],
                    ...$line['contribution']->toArray(2),
                ]);

                $this->pantry->consume($line['item'], $line['quantity'], $event->id, $consumedAt);
            }

            return $event;
        });
    }

    /**
     * Log an eating-out meal (restaurant/cafe/takeaway). Figures are the user's
     * or a typical-composition ESTIMATE — stored as given, marked `estimated`,
     * and any figure they don't know stays an honest null (brief §2.1). A
     * coarse entry beats a gap: completeness of the ledger outranks precision.
     *
     * @param  array<string, float|null>  $figures  subset of NutrientValues::KEYS
     * @param  NutrientOrigins|null  $origins  where each figure came from. A figure a
     *                                         model produced must arrive marked, or it becomes
     *                                         indistinguishable from one the user read off a menu.
     */
    public function logEatingOut(
        User $user,
        string $name,
        array $figures = [],
        ?Carbon $consumedAt = null,
        ?string $venue = null,
        ?NutrientOrigins $origins = null,
    ): ConsumptionEvent {
        $name = trim($name);
        $venue = $venue !== null && trim($venue) !== '' ? mb_substr(trim($venue), 0, 120) : null;

        if ($name === '') {
            throw new InvalidArgumentException('An eating-out entry needs a name.');
        }

        $values = [];

        foreach (NutrientValues::KEYS as $key) {
            $value = $figures[$key] ?? null;

            if ($value !== null && (! is_numeric($value) || (float) $value < 0 || (float) $value > 99999)) {
                throw new InvalidArgumentException("Figure {$key} must be a sensible non-negative number.");
            }

            $values[$key] = $value === null ? null : round((float) $value, 2);
        }

        return ConsumptionEvent::create([
            'user_id' => $user->id,
            'type' => ConsumptionType::Meal,
            'context' => MealContext::EatingOut,
            'estimated' => true,
            'name' => $name,
            'venue' => $venue,
            'consumed_at' => $consumedAt ?? now(),
            ...$values,
            'nutrient_origins' => ($origins ?? NutrientOrigins::none())->toArray(),
        ]);
    }

    /**
     * Edit a single-item consumption (brief §8.6): change the amount and/or the
     * time. The snapshot is RECOMPUTED for the new amount — against the version
     * originally eaten, so editing a past entry never adopts a later
     * reformulation — and the pantry deduction is adjusted with a compensating
     * correction so the ledger stays consistent and reconcile holds.
     *
     * Meals are not amount-editable — "1.5 of a meal" has no meaning per line;
     * delete and re-log instead. (Per-line editing arrives with the meal
     * builder proper, M5.)
     */
    public function editConsumption(
        ConsumptionEvent $event,
        float $quantity,
        ?QuantityUnit $unit = null,
        ?Carbon $consumedAt = null,
    ): ConsumptionEvent {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A positive quantity is required.');
        }

        if ($event->type === ConsumptionType::Meal) {
            throw new InvalidArgumentException('Meals cannot be amount-edited; delete and re-log instead.');
        }

        return DB::transaction(function () use ($event, $quantity, $unit, $consumedAt) {
            $line = $event->items()->firstOrFail();
            $pantryItem = $this->pantryItemFor($event);

            $resolvedUnit = $pantryItem !== null
                ? $this->resolveUnit($pantryItem, $unit)
                : ($unit ?? $line->unit);

            // Recompute against the version actually eaten (history-preserving),
            // falling back to the current version only if that version is gone.
            $version = $line->productVersion
                ?? ($pantryItem !== null ? $this->pantryNutrition->currentVersion($pantryItem->canonicalProduct) : null);
            $product = $line->canonicalProduct ?? $pantryItem?->canonicalProduct;

            $contribution = $this->contributionFor($product, $version, $quantity, $resolvedUnit);

            $line->update([
                'quantity' => $quantity,
                'unit' => $resolvedUnit,
                // An edited amount is a custom amount — the original natural-
                // language portion ("half the pack") no longer describes it.
                'portion_label' => null,
                ...$contribution->toArray(2),
            ]);

            $event->update([
                'consumed_at' => $consumedAt ?? $event->consumed_at,
                ...$contribution->toArray(2),
            ]);

            // Move the ledger from its current net effect to the new target.
            if ($pantryItem !== null) {
                $targetNet = -$quantity;                       // desired net removal
                $delta = $targetNet - $this->linkedNet($event); // signed compensating move
                $this->pantry->adjust($pantryItem, $delta, $event->id, $consumedAt ?? $event->consumed_at);
            }

            return $event->refresh();
        });
    }

    /**
     * Delete a consumption (brief §8.6): restore the exact quantity this event
     * removed from the pantry via a compensating correction, then remove the
     * event (its items cascade; any linked ledger rows null-out but survive), so
     * the cached balance and ledger stay consistent and reconcile holds.
     */
    public function deleteConsumption(ConsumptionEvent $event): void
    {
        DB::transaction(function () use ($event) {
            // A meal deducts from SEVERAL pantry items — restore each item's own
            // net effect, not just the first linked one.
            $netByItem = $event->pantryTransactions()
                ->with('pantryItem')
                ->get()
                ->groupBy('pantry_item_id');

            foreach ($netByItem as $transactions) {
                $pantryItem = $transactions->first()->pantryItem;

                if ($pantryItem === null) {
                    continue;
                }

                $net = round((float) $transactions->sum('quantity_delta'), 3);

                if (abs($net) > 1e-9) {
                    // Net effect is negative (stock removed); restoring it is +delta.
                    $this->pantry->adjust($pantryItem, -$net, null, now());
                }
            }

            $event->delete();
        });
    }

    /**
     * The nutrient contribution of `$quantity $unit`, delegated wholly to
     * {@see NutritionCalculator}. Returns all-unknown (never fabricated 0) when
     * there is no usable version or the unit needs a size the version lacks.
     */
    private function contributionFor(
        ?CanonicalProduct $product,
        ?ProductVersion $version,
        float $quantity,
        QuantityUnit $unit,
    ): NutrientValues {
        if ($version === null) {
            return NutrientValues::unknown();
        }

        try {
            return $this->calculator->contribution(
                NutrientValues::fromArray($version->only(NutrientValues::KEYS)),
                $version->serving_basis,
                $version->servingSize(),
                $quantity,
                $unit,
                $product?->packSize(),
            );
        } catch (InvalidArgumentException) {
            return NutrientValues::unknown();
        }
    }

    /** The pantry item this event drew from, via its linked ledger rows. */
    private function pantryItemFor(ConsumptionEvent $event): ?PantryItem
    {
        return $event->pantryTransactions()
            ->with('pantryItem')
            ->first()?->pantryItem;
    }

    /** The event's live net effect on stock (sum of its linked ledger deltas). */
    private function linkedNet(ConsumptionEvent $event): float
    {
        return round((float) $event->pantryTransactions()->sum('quantity_delta'), 3);
    }

    private function resolveUnit(PantryItem $item, ?QuantityUnit $unit): QuantityUnit
    {
        if ($unit !== null && $unit !== $item->quantity_unit) {
            throw new InvalidArgumentException(
                'Consumption unit must match the pantry item unit; cross-unit consumption arrives with meals.',
            );
        }

        return $unit ?? $item->quantity_unit;
    }

    private function productLabel(CanonicalProduct $product): string
    {
        return $product->displayName();
    }
}
