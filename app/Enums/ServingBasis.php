<?php

namespace App\Enums;

/**
 * Whether a product version's nutrient figures are quoted per 100g/100ml or
 * per stated serving (BUILD_PLAN §5, §7.12).
 *
 * The original basis is always preserved on the row; NutritionCalculator
 * derives the other deterministically when needed.
 */
enum ServingBasis: string
{
    case Per100g = 'per_100g';
    case PerServing = 'per_serving';
}
