<?php

namespace App\Enums;

/**
 * Where a logged meal came from — the fidelity tier of its figures (BUILD_PLAN
 * "Reframe, Aug 2026"). The ledger records every meal; the context says how
 * trustworthy the numbers are and which capture flow produced them.
 *
 * - Pantry:     a tracked pantry product — exact, label-derived data.
 * - HomeCooked: composed from pantry components — near-exact.
 * - EatingOut:  restaurant/cafe/takeaway — estimated or unknown figures,
 *               always marked as such. A coarse entry beats a gap: the diet
 *               picture's worst failure is the missing meal.
 */
enum MealContext: string
{
    case Pantry = 'pantry';
    case HomeCooked = 'home_cooked';
    case EatingOut = 'eating_out';

    public function label(): string
    {
        return match ($this) {
            self::Pantry => 'From your pantry',
            self::HomeCooked => 'Home-cooked',
            self::EatingOut => 'Eating out',
        };
    }
}
