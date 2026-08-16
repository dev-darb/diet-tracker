<?php

namespace App\Nutrition;

/**
 * The unit a nutrient's canonical figure is stored in.
 *
 * Open Food Facts normalises its `*_100g` weight nutriments to GRAMS, which is
 * unusable for micronutrients — vitamin B12 arrives as 0.0000006 and would round
 * to zero in any sane decimal column. So each nutrient declares the unit its
 * stored figure is quoted in, and {@see Nutrient::$sources} carries the
 * multiplier that converts the source's figure into it.
 *
 * The enum VALUE is ASCII on purpose (it reaches the database and config);
 * the micro sign lives only in the display label.
 */
enum NutrientUnit: string
{
    case Kcal = 'kcal';
    case Gram = 'g';
    case Milligram = 'mg';
    case Microgram = 'ug';

    /** How the unit is written for a person: "µg", not "ug". */
    public function label(): string
    {
        return match ($this) {
            self::Kcal => 'kcal',
            self::Gram => 'g',
            self::Milligram => 'mg',
            self::Microgram => 'µg',
        };
    }

    /** Decimal places a stored figure of this unit is rounded to when persisted. */
    public function precision(): int
    {
        return match ($this) {
            self::Kcal, self::Gram => 2,
            // Micro figures are small and scale by portion; keep three places so a
            // 30 g portion of a 0.001 mg/100g nutrient is not rounded out of existence.
            self::Milligram, self::Microgram => 3,
        };
    }
}
