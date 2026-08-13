<?php

namespace App\Enums;

/**
 * The unit a pantry balance / transaction / consumption is measured in
 * (BUILD_PLAN §5; brief §8.2).
 *
 * - `g` / `ml`  — mass / volume.
 * - `unit`      — a whole item, taken as one stated serving of the product.
 * - `portion`   — a stated serving/portion of the product (synonym of one unit
 *                 for calculation purposes).
 * - `pack`      — a whole pack (needs the product's pack size to resolve to
 *                 g/ml).
 */
enum QuantityUnit: string
{
    case Unit = 'unit';
    case Gram = 'g';
    case Millilitre = 'ml';
    case Pack = 'pack';
    case Portion = 'portion';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Units',
            self::Gram => 'Grams (g)',
            self::Millilitre => 'Millilitres (ml)',
            self::Pack => 'Packs',
            self::Portion => 'Portions',
        };
    }

    /** Short suffix for rendering a balance, e.g. "600g" / "2 units". */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Gram => 'g',
            self::Millilitre => 'ml',
            self::Unit => 'units',
            self::Pack => 'packs',
            self::Portion => 'portions',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $u) => [
            'value' => $u->value,
            'label' => $u->label(),
        ], self::cases());
    }
}
