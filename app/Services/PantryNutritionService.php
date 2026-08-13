<?php

namespace App\Services;

use App\Enums\ProductVerificationStatus;
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
