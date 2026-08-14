<?php

namespace App\Services;

use App\Enums\QuantityUnit;
use App\Models\ConsumptionItem;
use App\Models\PantryItem;
use App\Models\User;

/**
 * Natural-portion suggestions for the Consume action (Phase 1 of the
 * portions design; brief §8.3 "all / half / custom" made product-aware).
 *
 * People don't think in abstract units — they eat "one", "half the pack",
 * "a serving", or "what I had last time". This service turns what we already
 * know about a product (pack size, stated serving size, the user's own last
 * portion) into a short list of tappable options.
 *
 * DETERMINISM CONTRACT (brief §2.1/§8.9): every suggestion resolves to a plain
 * numeric quantity IN THE PANTRY ITEM'S OWN UNIT before it reaches
 * ConsumptionService — the label is presentation only, and this service does no
 * nutrient arithmetic. Cross-unit consumption (grams from a unit-stored item)
 * stays deferred to the meal builder (M5), exactly as ConsumptionService
 * documents.
 *
 * Each suggestion:
 *   - label    what the chip says ("I ate one", "Half the pack")
 *   - hint     small print with the resolved amount ("48 g"), null when unknown
 *   - quantity the amount consumed, in the item's unit
 *   - record   the wording snapshotted onto the consumption row so history can
 *              echo it back ("one (48 g)")
 *
 * @phpstan-type Suggestion array{label: string, hint: string|null, quantity: float, record: string}
 */
class PortionSuggestionService
{
    /** Chips beyond this are noise on a phone screen. */
    private const MAX_SUGGESTIONS = 4;

    public function __construct(private readonly PantryNutritionService $nutrition) {}

    /**
     * @return list<Suggestion>
     */
    public function suggestionsFor(PantryItem $item, User $user): array
    {
        $unit = $item->quantity_unit;

        $suggestions = match ($unit) {
            QuantityUnit::Unit, QuantityUnit::Portion => $this->forCountedItem($item),
            QuantityUnit::Pack => $this->forPackItem($item),
            QuantityUnit::Gram, QuantityUnit::Millilitre => $this->forMeasuredItem($item),
        };

        $suggestions = $this->prependLastPortion($suggestions, $item, $user);

        return array_slice(array_values($suggestions), 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Items counted in wholes (a bar, a pot, a can). Pack size, when known,
     * annotates each count with its real weight/volume.
     *
     * @return list<Suggestion>
     */
    private function forCountedItem(PantryItem $item): array
    {
        $packHint = $this->packAmount($item);
        $balance = (float) $item->current_quantity;

        $suggestions = [
            $this->suggestion('I ate one', 1.0, $packHint, 'one'),
            $this->suggestion('Half of one', 0.5, $this->scaledAmount($packHint, 0.5), 'half of one'),
        ];

        // Finishing the stock is a real moment ("polished them off") — offer it
        // when it isn't just a duplicate of "one".
        if ($balance > 0 && abs($balance - 1.0) > 1e-9 && abs($balance - 0.5) > 1e-9) {
            $suggestions[] = $this->suggestion(
                'All of them',
                round($balance, 3),
                $this->scaledAmount($packHint, $balance),
                'all of them',
            );
        }

        return $suggestions;
    }

    /**
     * Items stocked as whole packs.
     *
     * @return list<Suggestion>
     */
    private function forPackItem(PantryItem $item): array
    {
        $packHint = $this->packAmount($item);

        return [
            $this->suggestion('The whole pack', 1.0, $packHint, 'the whole pack'),
            $this->suggestion('Half the pack', 0.5, $this->scaledAmount($packHint, 0.5), 'half the pack'),
        ];
    }

    /**
     * Items measured loose in g/ml (rice, milk, spread). The product's stated
     * serving and the pack itself give natural anchors; quantities are already
     * in the item's own unit so no conversion happens here.
     *
     * @return list<Suggestion>
     */
    private function forMeasuredItem(PantryItem $item): array
    {
        $unitSuffix = $item->quantity_unit->shortLabel();
        $suggestions = [];

        $serving = $this->servingSizeInItemUnit($item);

        if ($serving !== null) {
            $suggestions[] = $this->suggestion(
                'A serving',
                $serving,
                null,
                sprintf('a serving (%s %s)', $this->trim($serving), $unitSuffix),
                $this->trim($serving).' '.$unitSuffix,
            );
        }

        $pack = $this->packSizeInItemUnit($item);

        if ($pack !== null) {
            $suggestions[] = $this->suggestion(
                'Half the pack',
                round($pack / 2, 3),
                $this->trim(round($pack / 2, 3)).' '.$unitSuffix,
                sprintf('half the pack (%s %s)', $this->trim(round($pack / 2, 3)), $unitSuffix),
            );
            $suggestions[] = $this->suggestion(
                'The whole pack',
                $pack,
                $this->trim($pack).' '.$unitSuffix,
                sprintf('the whole pack (%s %s)', $this->trim($pack), $unitSuffix),
            );
        }

        return $this->dedupeByQuantity($suggestions);
    }

    /**
     * "Same as last time" — most people eat the same portion of the same
     * product repeatedly, so their own last log is the best first guess.
     * Skipped when it duplicates an existing chip's amount (the named chip
     * reads better) or when the unit no longer matches the item's.
     *
     * @param  list<Suggestion>  $suggestions
     * @return list<Suggestion>
     */
    private function prependLastPortion(array $suggestions, PantryItem $item, User $user): array
    {
        $last = ConsumptionItem::query()
            ->where('canonical_product_id', $item->canonical_product_id)
            ->where('unit', $item->quantity_unit->value)
            ->whereHas('consumptionEvent', fn ($query) => $query->where('user_id', $user->id))
            ->latest('id')
            ->first();

        if ($last === null) {
            return $suggestions;
        }

        $quantity = round((float) $last->quantity, 3);

        if ($quantity <= 0) {
            return $suggestions;
        }

        foreach ($suggestions as $existing) {
            if (abs($existing['quantity'] - $quantity) < 1e-9) {
                return $suggestions; // already covered by a better-named chip
            }
        }

        $amount = $this->trim($quantity).' '.$item->quantity_unit->shortLabelFor($quantity);
        $record = $last->portion_label ?? $amount;

        array_unshift($suggestions, $this->suggestion('Same as last time', $quantity, $last->portion_label ?? $amount, $record));

        return $suggestions;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array{label: string, hint: string|null, quantity: float, record: string}
     */
    private function suggestion(string $label, float $quantity, ?string $hint, string $recordBase, ?string $recordAmount = null): array
    {
        // The stored record carries the resolved amount when we know it, so
        // history stays meaningful even if the product's pack changes later.
        $amount = $recordAmount ?? $hint;

        return [
            'label' => $label,
            'hint' => $hint,
            'quantity' => $quantity,
            'record' => $amount !== null && ! str_contains($recordBase, '(') ? "{$recordBase} ({$amount})" : $recordBase,
        ];
    }

    /** Pack size rendered as "48 g" when stated in g/ml; null otherwise. */
    private function packAmount(PantryItem $item): ?string
    {
        $product = $item->canonicalProduct;

        if ($product->pack_size_value === null || ! in_array($product->pack_size_unit, ['g', 'ml'], true)) {
            return null;
        }

        return $this->trim((float) $product->pack_size_value).' '.$product->pack_size_unit;
    }

    /** Scale a "48 g"-style amount string by a factor; null passes through. */
    private function scaledAmount(?string $amount, float $factor): ?string
    {
        if ($amount === null) {
            return null;
        }

        [$value, $unit] = explode(' ', $amount, 2);

        return $this->trim(round((float) $value * $factor, 1)).' '.$unit;
    }

    /** The product's stated serving size, only when it matches the item's unit. */
    private function servingSizeInItemUnit(PantryItem $item): ?float
    {
        $version = $this->nutrition->currentVersion($item->canonicalProduct);

        if ($version === null || $version->serving_size_value === null) {
            return null;
        }

        return $version->serving_size_unit === $item->quantity_unit->value
            ? round((float) $version->serving_size_value, 3)
            : null;
    }

    /** The pack size, only when stated in the item's own unit. */
    private function packSizeInItemUnit(PantryItem $item): ?float
    {
        $product = $item->canonicalProduct;

        if ($product->pack_size_value === null || $product->pack_size_unit !== $item->quantity_unit->value) {
            return null;
        }

        return round((float) $product->pack_size_value, 3);
    }

    /**
     * @param  list<Suggestion>  $suggestions
     * @return list<Suggestion>
     */
    private function dedupeByQuantity(array $suggestions): array
    {
        $seen = [];
        $result = [];

        foreach ($suggestions as $suggestion) {
            $key = (string) round($suggestion['quantity'], 3);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $suggestion;
            }
        }

        return $result;
    }

    /** "48.000" -> "48", "0.500" -> "0.5". */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
