<?php

namespace App\Services;

use App\Enums\ConsumptionType;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\PantryItem;
use App\Models\ProductVersion;
use App\Models\User;
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
     */
    public function consumePantryItem(
        User $user,
        PantryItem $item,
        float $quantity,
        ?QuantityUnit $unit = null,
        ?Carbon $consumedAt = null,
    ): ConsumptionEvent {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A positive quantity is required to consume.');
        }

        $unit = $this->resolveUnit($item, $unit);
        $consumedAt ??= now();

        return DB::transaction(function () use ($user, $item, $quantity, $unit, $consumedAt) {
            $product = $item->canonicalProduct;
            $version = $this->pantryNutrition->currentVersion($product);
            $contribution = $this->contributionFor($product, $version, $quantity, $unit);

            $event = ConsumptionEvent::create([
                'user_id' => $user->id,
                'type' => ConsumptionType::Single,
                'name' => $this->productLabel($product),
                'consumed_at' => $consumedAt,
                ...$contribution->toArray(2),
            ]);

            $event->items()->create([
                'canonical_product_id' => $product->id,
                'product_version_id' => $version?->id,
                'quantity' => $quantity,
                'unit' => $unit,
                ...$contribution->toArray(2),
            ]);

            // Deterministic inventory deduction, linked back to this event.
            $this->pantry->consume($item, $quantity, $event->id, $consumedAt);

            return $event;
        });
    }

    /**
     * Edit a single-item consumption (brief §8.6): change the amount and/or the
     * time. The snapshot is RECOMPUTED for the new amount — against the version
     * originally eaten, so editing a past entry never adopts a later
     * reformulation — and the pantry deduction is adjusted with a compensating
     * correction so the ledger stays consistent and reconcile holds.
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
            $pantryItem = $this->pantryItemFor($event);

            if ($pantryItem !== null) {
                // Net effect is negative (stock removed); restoring it is +delta.
                $this->pantry->adjust($pantryItem, -$this->linkedNet($event), null, now());
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

        $values = NutrientValues::fromArray($version->only(NutrientValues::KEYS));
        $servingSize = $version->serving_size_value !== null ? (float) $version->serving_size_value : null;
        $packSize = $product !== null && $product->pack_size_value !== null
            ? (float) $product->pack_size_value
            : null;

        try {
            return $this->calculator->contribution(
                $values,
                $version->serving_basis,
                $servingSize,
                $quantity,
                $unit,
                $packSize,
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
        return trim($product->brand.' '.$product->name);
    }
}
