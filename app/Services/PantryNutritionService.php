<?php

namespace App\Services;

use App\Enums\ProductVerificationStatus;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Models\PantryItem;
use App\Models\ProductVersion;
use App\ValueObjects\NutrientValues;
use InvalidArgumentException;

/**
 * Read-model that turns a pantry item's cached balance into nutrition figures
 * (BUILD_PLAN §7.9). All arithmetic is delegated to {@see NutritionCalculator}
 * — this service only selects the applicable product version and marshals the
 * item's quantity/unit into it. It never recomputes nutrients inline (brief
 * §8.9: the deterministic engine is the single source of that maths).
 */
class PantryNutritionService
{
    public function __construct(private readonly NutritionCalculator $calculator) {}

    /**
     * The nutrition version currently in effect for a product: the most recent
     * `effective_from` among versions that have not been rejected or superseded.
     */
    public function currentVersion(CanonicalProduct $product): ?ProductVersion
    {
        return $product->versions()
            ->whereNotIn('status', [
                ProductVerificationStatus::Rejected->value,
                ProductVerificationStatus::Superseded->value,
            ])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Nutrition contributed by everything currently held in a pantry item.
     * Returns null when the product has no usable version, or when the item's
     * unit needs a serving/pack size the version does not carry.
     */
    public function currentNutrition(PantryItem $item): ?NutrientValues
    {
        $version = $this->currentVersion($item->canonicalProduct);

        if ($version === null) {
            return null;
        }

        return $this->nutritionFor($item, $version, (float) $item->current_quantity);
    }

    /**
     * Nutrition for a specific quantity of an item's product (e.g. previewing a
     * consume amount before committing it). Same rules as {@see currentNutrition}.
     */
    public function nutritionForQuantity(PantryItem $item, float $quantity): ?NutrientValues
    {
        $version = $this->currentVersion($item->canonicalProduct);

        if ($version === null) {
            return null;
        }

        return $this->nutritionFor($item, $version, $quantity);
    }

    /**
     * A representative nutrition figure for a product that is NOT yet in a
     * pantry — the Scan "Is this right?" confirmation (brief §7.6). Prefers a
     * per-serving figure when the current version states a serving size,
     * otherwise the version's own basis (per 100g/ml). Returns null when the
     * product has no usable version. All arithmetic goes through
     * {@see NutritionCalculator}; unknown nutrients stay null (never fabricated).
     *
     * @return array{values: NutrientValues, basis_label: string}|null
     */
    public function productSummary(CanonicalProduct $product): ?array
    {
        $version = $this->currentVersion($product);

        if ($version === null) {
            return null;
        }

        $values = NutrientValues::fromArray($version->only(NutrientValues::KEYS));
        $serving = $version->serving_size_value !== null ? (float) $version->serving_size_value : null;

        if ($serving !== null && $serving > 0.0) {
            $unit = $version->serving_size_unit ?: 'g';

            return [
                'values' => $this->calculator->convertBasis($values, $version->serving_basis, ServingBasis::PerServing, $serving),
                'basis_label' => 'per serving ('.rtrim(rtrim(number_format($serving, 3, '.', ''), '0'), '.').$unit.')',
            ];
        }

        return [
            'values' => $values,
            'basis_label' => $version->serving_basis === ServingBasis::Per100g ? 'per 100g / 100ml' : 'per serving',
        ];
    }

    private function nutritionFor(PantryItem $item, ProductVersion $version, float $quantity): ?NutrientValues
    {
        $values = NutrientValues::fromArray($version->only(NutrientValues::KEYS));

        $servingSize = $version->serving_size_value !== null ? (float) $version->serving_size_value : null;
        $packSize = $item->canonicalProduct->pack_size_value !== null
            ? (float) $item->canonicalProduct->pack_size_value
            : null;

        try {
            return $this->calculator->contribution(
                $values,
                $version->serving_basis,
                $servingSize,
                $quantity,
                $item->quantity_unit,
                $packSize,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
