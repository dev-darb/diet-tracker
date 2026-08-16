<?php

namespace App\Services;

use App\AI\DataObjects\DietInsightContext;
use App\Enums\ServingBasis;
use App\Models\PantryItem;
use App\Models\User;
use App\ValueObjects\NutrientValues;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Assembles the DETERMINISTIC {@see DietInsightContext} the insight generators
 * interpret (BUILD_PLAN §6 J7.1; brief §9.7/§9.8).
 *
 * It stitches together three already-computed, deterministic pieces and does NO
 * nutrient arithmetic of its own beyond a documented basis conversion delegated
 * to {@see NutritionCalculator}:
 *
 *  1. the {@see NutritionAnalyticsService} weekly summary (the numbers);
 *  2. the user's goal / dietary context (for prioritisation only);
 *  3. a compact list of the current pantry with each item's key per-100(g/ml)
 *     nutrients + category, so insights can be pantry-aware (§9.7).
 *
 * Crucially it hands the generators the COMPUTED summary + current pantry only —
 * never raw consumption history (brief §9.8).
 */
class DietInsightContextBuilder
{
    public function __construct(
        private readonly NutritionAnalyticsService $analytics,
        private readonly PantryNutritionService $pantryNutrition,
        private readonly NutritionCalculator $calculator,
    ) {}

    /**
     * Build the context for a user's current week (or a week ending $endDate).
     */
    public function build(User $user, ?Carbon $endDate = null): DietInsightContext
    {
        $weekly = $this->analytics->weeklySummary($user, $endDate);

        return new DietInsightContext(
            userId: $user->id,
            periodStart: $weekly['start'],
            periodEnd: $weekly['end'],
            weekly: $weekly,
            pantry: $this->pantrySnapshot($user),
            profile: $this->profileContext($user),
        );
    }

    /**
     * A compact, deterministic snapshot of the user's current stock. Each entry
     * carries the item's display name, category and its nutrients normalised to
     * per-100(g/ml) so a generator can decide, without any AI, whether the item
     * is a good source of the focus nutrient (e.g. "high in fibre"). Items with
     * no usable version are still listed (name only) but their nutrients are
     * unknown, so they never get claimed as a source (brief §2.1).
     *
     * @return array<int, array<string, mixed>>
     */
    private function pantrySnapshot(User $user): array
    {
        /** @var Collection<int, PantryItem> $items */
        $items = $user->pantryItems()
            ->where('current_quantity', '>', 0)
            ->with('canonicalProduct')
            ->get();

        return $items->map(function (PantryItem $item): array {
            $product = $item->canonicalProduct;

            return [
                'id' => $item->id,
                'name' => $this->displayName($item),
                'category' => $product?->category,
                'quantity' => (float) $item->current_quantity,
                'unit' => $item->quantity_unit?->value,
                'per_100g' => $this->per100g($item)->toArray(1),
            ];
        })->all();
    }

    /**
     * The item's current nutrient figures normalised to a per-100(g/ml) basis.
     * All arithmetic is delegated to {@see NutritionCalculator}; if the current
     * version cannot be expressed per-100 (e.g. per-serving with no serving
     * size), the figures stay UNKNOWN rather than being fabricated.
     */
    private function per100g(PantryItem $item): NutrientValues
    {
        $version = $item->canonicalProduct !== null
            ? $this->pantryNutrition->currentVersion($item->canonicalProduct)
            : null;

        if ($version === null) {
            return NutrientValues::unknown();
        }

        $values = NutrientValues::fromArray($version->only(NutrientValues::KEYS));

        if ($version->serving_basis === ServingBasis::Per100g) {
            return $values;
        }

        try {
            return $this->calculator->convertBasis(
                $values,
                ServingBasis::PerServing,
                ServingBasis::Per100g,
                $version->servingSize(),
            );
        } catch (Throwable) {
            // Per-serving figures with no serving size can't be normalised — stay honest.
            return NutrientValues::unknown();
        }
    }

    private function displayName(PantryItem $item): string
    {
        $product = $item->canonicalProduct;

        if ($product === null) {
            return 'Item #'.$item->id;
        }

        return trim(implode(' ', array_filter([
            $product->brand,
            $product->name,
            $product->variant,
        ]))) ?: ($product->name ?? 'Item #'.$item->id);
    }

    /**
     * Goal + dietary context for prioritisation (brief §6.6, §9.7). No PII beyond
     * what personalises guidance; this is deliberately small.
     *
     * @return array<string, mixed>
     */
    private function profileContext(User $user): array
    {
        $profile = $user->profile;

        if ($profile === null) {
            return ['goal' => null, 'goal_label' => null];
        }

        return [
            'goal' => $profile->primary_goal?->value,
            'goal_label' => $profile->primary_goal?->label(),
            'dietary_pattern' => $profile->dietary_pattern?->value,
            'dietary_preferences' => $profile->dietary_preferences ?? [],
            'allergies' => $profile->allergies ?? [],
            'avoided_foods' => $profile->avoided_foods ?? [],
        ];
    }
}
