<?php

namespace App\Nutrition;

use App\ValueObjects\NutrientValues;

/**
 * Whether a nutrient is one of the eight the app has always tracked, or one of
 * the label micronutrients added in the food-intelligence tranche (Aug 2026).
 *
 * The distinction is not cosmetic — it decides what an ABSENT value means when a
 * caller hand-builds a partial nutrient set. See
 * {@see NutrientValues::fromArray()}: a missing macro is a
 * deliberate "no contribution" under the app's original contract, while a
 * missing micro was never in that contract and can only honestly mean unknown.
 */
enum NutrientGroup: string
{
    /** Energy and the seven macros: always present as columns, usually stated. */
    case Macro = 'macro';

    /** Vitamins and minerals: always present as columns, frequently NOT stated. */
    case Micro = 'micro';
}
