<?php

namespace App\Services;

use App\Enums\PantryTransactionType;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\PantryItem;
use App\Models\PantryTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The pantry ledger service (BUILD_PLAN §3 idea #5; brief §7.7/§8.7).
 *
 * Every mutation writes an IMMUTABLE `pantry_transactions` row AND updates the
 * cached `pantry_items.current_quantity` inside ONE database transaction, so the
 * cache can never disagree with the ledger. The ledger is the source of truth;
 * {@see recomputeBalance()} / {@see reconcile()} verify the cache against it.
 *
 * All quantities are held to 3 decimal places (matching the decimal(12,3)
 * columns) so fractional grams/ml/packs reconcile exactly without float drift.
 *
 * OVER-CONSUME RULE (documented): consume / discard / manual_remove clamp the
 * removed amount to the current balance, so `current_quantity` never goes
 * negative and the recorded delta reflects what was actually removed. A pantry
 * physically cannot hold less than nothing, and clamping keeps the invariant
 * cache == Σ ledger intact. Use {@see correct()} to set an exact known balance.
 */
class PantryService
{
    /** Precision of every stored quantity (decimal(12,3) columns). */
    private const SCALE = 3;

    /**
     * Add stock: create the pantry item (or reuse the matching one) and append
     * a `purchase` transaction.
     *
     * @param  array<string, mixed>  $meta  optional purchased_at / expiry_date.
     */
    public function purchase(
        User $user,
        CanonicalProduct $product,
        float $quantity,
        QuantityUnit $unit,
        array $meta = [],
        ?Carbon $occurredAt = null,
    ): PantryItem {
        $this->assertPositive($quantity, 'purchase');

        return DB::transaction(function () use ($user, $product, $quantity, $unit, $meta, $occurredAt) {
            $item = PantryItem::query()
                ->where('user_id', $user->id)
                ->where('canonical_product_id', $product->id)
                ->where('quantity_unit', $unit->value)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                $item = new PantryItem([
                    'user_id' => $user->id,
                    'canonical_product_id' => $product->id,
                    'current_quantity' => 0,
                    'quantity_unit' => $unit,
                    'purchased_at' => $meta['purchased_at'] ?? now(),
                    'expiry_date' => $meta['expiry_date'] ?? null,
                ]);
                $item->save();
            } else {
                // Refresh optional metadata on re-purchase without losing history.
                if (array_key_exists('purchased_at', $meta)) {
                    $item->purchased_at = $meta['purchased_at'];
                }
                if (array_key_exists('expiry_date', $meta)) {
                    $item->expiry_date = $meta['expiry_date'];
                }
            }

            $this->applyDelta($item, PantryTransactionType::Purchase, $quantity, $occurredAt);

            return $item;
        });
    }

    /**
     * Consume stock. `$linkedConsumptionEventId` is wired for Milestone 4;
     * leave it null for now. Clamped per the over-consume rule.
     */
    public function consume(
        PantryItem $item,
        float $quantity,
        ?int $linkedConsumptionEventId = null,
        ?Carbon $occurredAt = null,
    ): PantryTransaction {
        return $this->removeStock($item, PantryTransactionType::Consume, $quantity, $linkedConsumptionEventId, $occurredAt);
    }

    /** Throw stock away (spoiled/expired). Clamped per the over-consume rule. */
    public function discard(PantryItem $item, float $quantity, ?Carbon $occurredAt = null): PantryTransaction
    {
        return $this->removeStock($item, PantryTransactionType::Discard, $quantity, null, $occurredAt);
    }

    /** Manually remove stock (user tidy-up). Clamped per the over-consume rule. */
    public function manualRemove(PantryItem $item, float $quantity, ?Carbon $occurredAt = null): PantryTransaction
    {
        return $this->removeStock($item, PantryTransactionType::ManualRemove, $quantity, null, $occurredAt);
    }

    /**
     * Correct the balance to a known-true absolute value. The recorded delta is
     * the signed difference needed to reach `$targetQuantity`, so the ledger
     * still reconciles. Unlike removals this is NOT clamped to the old balance
     * (that is the whole point of a correction), but the target itself must be
     * non-negative.
     */
    public function correct(PantryItem $item, float $targetQuantity, ?Carbon $occurredAt = null): PantryTransaction
    {
        if ($targetQuantity < 0) {
            throw new InvalidArgumentException('A correction target cannot be negative.');
        }

        return DB::transaction(function () use ($item, $targetQuantity, $occurredAt) {
            $item = $this->lock($item);
            $delta = $this->round($targetQuantity - (float) $item->current_quantity);

            return $this->applyDelta($item, PantryTransactionType::Correction, $delta, $occurredAt);
        });
    }

    /** Recompute the true balance from the immutable ledger. */
    public function recomputeBalance(PantryItem $item): float
    {
        return $this->round((float) $item->transactions()->sum('quantity_delta'));
    }

    /** True when the cached balance equals the ledger sum (the core invariant). */
    public function reconcile(PantryItem $item): bool
    {
        return $this->round((float) $item->current_quantity) === $this->recomputeBalance($item);
    }

    /**
     * Shared removal path: guard positivity, clamp to available, append a
     * negative delta, update the cache — all inside one DB transaction.
     */
    private function removeStock(
        PantryItem $item,
        PantryTransactionType $type,
        float $quantity,
        ?int $linkedConsumptionEventId,
        ?Carbon $occurredAt,
    ): PantryTransaction {
        $this->assertPositive($quantity, $type->value);

        return DB::transaction(function () use ($item, $type, $quantity, $linkedConsumptionEventId, $occurredAt) {
            $item = $this->lock($item);

            $available = (float) $item->current_quantity;
            $removed = min($quantity, max($available, 0.0)); // clamp: never below zero
            $delta = $this->round(-$removed);

            return $this->applyDelta($item, $type, $delta, $occurredAt, $linkedConsumptionEventId);
        });
    }

    /**
     * Append one ledger row and move the cached balance by the same delta.
     * Assumes it runs inside a DB transaction with the item row locked.
     */
    private function applyDelta(
        PantryItem $item,
        PantryTransactionType $type,
        float $delta,
        ?Carbon $occurredAt,
        ?int $linkedConsumptionEventId = null,
    ): PantryTransaction {
        $delta = $this->round($delta);

        $transaction = $item->transactions()->create([
            'type' => $type,
            'quantity_delta' => $delta,
            'unit' => $item->quantity_unit,
            'linked_consumption_event_id' => $linkedConsumptionEventId,
            'occurred_at' => $occurredAt ?? now(),
        ]);

        $item->current_quantity = $this->round((float) $item->current_quantity + $delta);
        $item->save();

        return $transaction;
    }

    private function lock(PantryItem $item): PantryItem
    {
        return PantryItem::query()->lockForUpdate()->findOrFail($item->getKey());
    }

    private function assertPositive(float $quantity, string $action): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("A positive quantity is required to {$action}.");
        }
    }

    private function round(float $value): float
    {
        return round($value, self::SCALE);
    }
}
