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
}
