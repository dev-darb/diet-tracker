<?php

namespace App\Models\Concerns;

use App\Nutrition\NutrientRegistry;
use App\ValueObjects\NutrientValues;

/**
 * Gives a model the nutrient columns {@see NutrientRegistry} declares — fillable
 * and cast — without writing twenty-three column names into three models by hand.
 *
 * Eloquent runs `initialize<TraitName>` on construction, so this merges into
 * whatever the model declares for itself; a model keeps its own fillable list
 * and `casts()` method for everything that is not nutrition.
 *
 * Adding a nutrient is therefore one registry entry plus one migration. That
 * matters: the audit (Aug 2026) found that energy was read only as kilocalories
 * and salt only as salt, bugs that survived precisely because a nutrient's
 * definition was scattered across six files and nobody could see the whole shape
 * of one in a single place.
 */
trait HasNutrientColumns
{
    public function initializeHasNutrientColumns(): void
    {
        $this->mergeFillable(NutrientValues::KEYS);
        $this->mergeCasts(NutrientRegistry::casts());
    }
}
