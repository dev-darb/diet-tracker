<?php

namespace App\Nutrition\Estimation;

/**
 * Why estimation is permitted for a particular food (founder decision, Aug 2026).
 *
 * The point of naming the reason is what it EXCLUDES. A model may produce a
 * nutrient figure — but only where a caller has decided, in advance and on the
 * record, that estimation is the right answer for this food. There is no reason
 * here that means "fill in the blanks", and that omission is the whole design:
 *
 *   An absent figure is not automatically an invitation. Open Food Facts
 *   leaving fibre unstated on a sourced product may be perfectly accurate —
 *   the manufacturer may not measure it — and quietly replacing that silence
 *   with a guess would turn an honest gap into a confident wrong answer, on a
 *   record the user has every reason to believe came from a label.
 *
 * So estimation applies to foods with NO source at all, where the alternative is
 * not "an honest unknown" but "the user cannot log their coffee". It does not
 * apply to holes in records that otherwise came from somewhere.
 */
enum EstimationReason: string
{
    /**
     * The food is not in any database we can reach — a flat white, a homemade
     * soup, a market pastry. Without an estimate there is no figure at all.
     */
    case NoSourceRecord = 'no_source_record';

    /**
     * A component identified in a meal photo that matched no canonical food. The
     * rest of the plate resolves; this part would otherwise silently vanish from
     * the meal, which understates it more than an estimate does.
     */
    case MealComponent = 'meal_component';

    /**
     * The user asked Foody directly to have a go. Their food, their call — and
     * the marker still shows.
     */
    case UserRequested = 'user_requested';

    public function label(): string
    {
        return match ($this) {
            self::NoSourceRecord => 'No source record for this food',
            self::MealComponent => 'Part of a meal with no matching food',
            self::UserRequested => 'You asked Foody to estimate',
        };
    }
}
