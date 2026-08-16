<?php

namespace App\Nutrition\Estimation;

/**
 * What an estimate's figures are quoted against.
 *
 * This has to be explicit or the guardrails cannot do their job: a plausibility
 * check for "per 100 g" is a completely different check from one for "a whole
 * plate of katsu curry", and 1,180 kcal is absurd in the first and ordinary in
 * the second.
 */
enum EstimationBasis: string
{
    /** Figures for the whole thing as served — a dish, a mug, a pastry. */
    case WholeItem = 'whole_item';

    /** Figures per 100 g or 100 ml, the basis a canonical food record uses. */
    case Per100 = 'per_100';

    public function label(): string
    {
        return match ($this) {
            self::WholeItem => 'the whole item as served',
            self::Per100 => 'per 100 g or 100 ml',
        };
    }
}
