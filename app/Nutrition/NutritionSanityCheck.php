<?php

namespace App\Nutrition;

use App\ValueObjects\NutrientValues;

/**
 * Deterministic plausibility checks on a per-100g/ml nutrition row (spec §3).
 *
 * Open Food Facts is crowd-sourced. Figures arrive with stray decimal points,
 * transposed columns, and units the contributor guessed at. Individually
 * implausible values are already rejected when they are read
 * ({@see Nutrient::readFrom()}); what this catches is the set of figures that
 * are each individually possible but cannot all be true at once — 90 g of fat
 * in something stating 100 kcal, more sugar than carbohydrate, a serving larger
 * than the pack it comes in.
 *
 * ## What it does NOT do
 * It never corrects a figure and never discards one. A "corrected" nutrient
 * would be a fabricated nutrient, which is the thing this whole subsystem
 * exists to prevent. It returns findings; the caller records them and drops the
 * version's status to needs-review so a person can look. The data stays exactly
 * as the source stated it.
 *
 * Pure: no database, no facades, no clock. Same input, same findings, always.
 */
final class NutritionSanityCheck
{
    /**
     * How far the Atwater estimate may sit from the stated energy before it is
     * worth mentioning. Generous on purpose: the factors are approximations,
     * polyols and organic acids are not tracked, and a check that fires on a
     * quarter of real products is a check nobody reads.
     */
    private const ENERGY_TOLERANCE = 0.25;

    /** Below this, percentage comparison of energy is meaningless. */
    private const ENERGY_FLOOR_KCAL = 50.0;

    /** Rounding slack, in grams per 100 g. */
    private const EPSILON = 0.5;

    /**
     * Atwater factors (kcal per gram) for the macros we track. Fibre is counted
     * at 2 because UK/EU labels quote carbohydrate EXCLUDING fibre, so its
     * energy would otherwise go missing from the estimate.
     */
    private const KCAL_PER_GRAM = [
        'protein' => 4.0,
        'carbs' => 4.0,
        'fat' => 9.0,
        'fibre' => 2.0,
    ];

    /**
     * Inspect a per-100g/ml row and its sizes.
     *
     * @return list<string> human-readable findings, empty when nothing is amiss.
     */
    public static function inspect(
        NutrientValues $per100,
        ?MeasuredAmount $servingSize = null,
        ?MeasuredAmount $packSize = null,
    ): array {
        $findings = [];

        foreach ([
            self::checkComponentExceedsParent($per100, 'sugars', 'carbs'),
            self::checkComponentExceedsParent($per100, 'saturated_fat', 'fat'),
            self::checkMassBudget($per100),
            self::checkEnergyAgainstMacros($per100),
            self::checkServingFitsPack($servingSize, $packSize),
        ] as $finding) {
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * The data-quality columns for a product_versions row: how much of the
     * tracked nutrition is actually stated, and what the cross-checks noticed.
     *
     * @return array{nutrient_coverage: float, sanity_findings: list<string>|null}
     */
    public static function columns(
        NutrientValues $per100,
        ?MeasuredAmount $servingSize = null,
        ?MeasuredAmount $packSize = null,
    ): array {
        $findings = self::inspect($per100, $servingSize, $packSize);

        return [
            'nutrient_coverage' => $per100->coverage(),
            // Null means "checked, nothing amiss" — distinct from an empty array,
            // which would read as "checked and produced no result".
            'sanity_findings' => $findings === [] ? null : $findings,
        ];
    }

    /** Sugars are part of carbohydrate; saturates are part of fat. */
    private static function checkComponentExceedsParent(NutrientValues $v, string $part, string $whole): ?string
    {
        $partValue = $v->get($part);
        $wholeValue = $v->get($whole);

        if ($partValue === null || $wholeValue === null || $partValue <= $wholeValue + self::EPSILON) {
            return null;
        }

        return sprintf(
            '%s (%.1f g) exceeds %s (%.1f g) per 100 g.',
            ucfirst(str_replace('_', ' ', $part)),
            $partValue,
            str_replace('_', ' ', $whole),
            $wholeValue,
        );
    }

    /** A hundred grams of food cannot contain more than a hundred grams of stuff. */
    private static function checkMassBudget(NutrientValues $v): ?string
    {
        $total = 0.0;

        foreach (['protein', 'carbs', 'fat', 'fibre', 'salt'] as $key) {
            $total += $v->get($key) ?? 0.0;
        }

        if ($total <= 100.0 + self::EPSILON) {
            return null;
        }

        return sprintf('Stated components total %.1f g per 100 g, which is more than the food weighs.', $total);
    }

    /**
     * Energy should roughly follow from the macros. A large disagreement usually
     * means a misplaced decimal point or a kJ figure filed as kcal.
     */
    private static function checkEnergyAgainstMacros(NutrientValues $v): ?string
    {
        $stated = $v->get('calories');

        if ($stated === null || $stated < self::ENERGY_FLOOR_KCAL) {
            return null;
        }

        $estimate = 0.0;

        foreach (self::KCAL_PER_GRAM as $key => $factor) {
            $value = $v->get($key);

            // Protein, carbohydrate and fat are the load-bearing three; without
            // all of them the estimate says nothing. Fibre may be absent.
            if ($value === null && $key !== 'fibre') {
                return null;
            }

            $estimate += ($value ?? 0.0) * $factor;
        }

        if ($estimate <= 0.0) {
            return null;
        }

        $drift = abs($stated - $estimate) / $stated;

        if ($drift <= self::ENERGY_TOLERANCE) {
            return null;
        }

        return sprintf(
            'Stated energy (%.0f kcal) is %.0f%% away from the %.0f kcal its macros imply per 100 g.',
            $stated,
            $drift * 100,
            $estimate,
        );
    }

    /** A serving larger than the whole pack means the two were swapped. */
    private static function checkServingFitsPack(?MeasuredAmount $serving, ?MeasuredAmount $pack): ?string
    {
        if ($serving === null || $pack === null || $serving->unit !== $pack->unit) {
            return null;
        }

        if ($serving->inBaseUnit() <= $pack->inBaseUnit() + self::EPSILON) {
            return null;
        }

        return sprintf(
            'Serving size (%s) is larger than the whole pack (%s) — the two may be swapped.',
            $serving->label(),
            $pack->label(),
        );
    }
}
