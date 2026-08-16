<?php

namespace App\Models\Concerns;

use App\Nutrition\NutrientOrigins;
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
        $this->mergeFillable([...NutrientValues::KEYS, 'nutrient_origins']);
        $this->mergeCasts([...NutrientRegistry::casts(), 'nutrient_origins' => 'array']);
    }

    /**
     * Where each of this row's figures came from. Nutrients absent from the map
     * were stated by a source, which is the ordinary case and the reason the
     * column is usually null.
     */
    public function nutrientOrigins(): NutrientOrigins
    {
        return NutrientOrigins::fromArray($this->nutrient_origins);
    }

    /** Whether any figure on this row was produced by a model rather than a source. */
    public function hasEstimatedNutrients(): bool
    {
        return $this->nutrientOrigins()->hasEstimates();
    }
}
