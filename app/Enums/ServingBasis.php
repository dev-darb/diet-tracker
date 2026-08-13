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

    public function label(): string
    {
        return match ($this) {
            self::Per100g => 'Per 100g / 100ml',
            self::PerServing => 'Per serving',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $b) => [
            'value' => $b->value,
            'label' => $b->label(),
        ], self::cases());
    }
}
