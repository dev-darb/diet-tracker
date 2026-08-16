<?php

namespace App\Nutrition;

use App\Enums\QuantityUnit;
use App\Services\NutritionCalculator;

/**
 * The only two units a nutrition calculation can actually use: grams and
 * millilitres.
 *
 * This enum IS the unit contract the audit's D5 found missing. Nutrition figures
 * are quoted per 100 g or per 100 ml, so every serving size and pack size that
 * reaches {@see NutritionCalculator} has to be a mass or a volume.
 * Before this existed, a pack size parsed as "1 kg" was multiplied as though it
 * were one gram, and a serving of "1 portion" as though it were one gram — a
 * whole bag of rice logged 0.78 g of carbohydrate instead of 780 g.
 *
 * Nothing outside these two cases can be converted, so an amount that will not
 * reduce to one of them is unknown, and unknown it stays.
 */
enum MeasureUnit: string
{
    case Gram = 'g';
    case Millilitre = 'ml';

    /** How the unit is written beside a number. */
    public function label(): string
    {
        return $this->value;
    }

    /** The stock-keeping unit this measure corresponds to. */
    public function toQuantityUnit(): QuantityUnit
    {
        return match ($this) {
            self::Gram => QuantityUnit::Gram,
            self::Millilitre => QuantityUnit::Millilitre,
        };
    }

    /** The measure a stock-keeping unit reduces to, when it reduces to one at all. */
    public static function fromQuantityUnit(QuantityUnit $unit): ?self
    {
        return match ($unit) {
            QuantityUnit::Gram => self::Gram,
            QuantityUnit::Millilitre => self::Millilitre,
            default => null,
        };
    }

    /** Read a unit written by a person or a database column ("G", "ml", "grams"). */
    public static function tryParse(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        return match (strtolower(trim($raw))) {
            'g', 'gram', 'grams', 'gr' => self::Gram,
            'ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters' => self::Millilitre,
            default => null,
        };
    }
}
