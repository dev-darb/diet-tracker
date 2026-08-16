<?php

namespace App\Services;

use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Nutrition\MeasuredAmount;
use App\ValueObjects\NutrientTotal;
use App\ValueObjects\NutrientValues;
use InvalidArgumentException;

/**
 * Deterministic nutrition engine (BUILD_PLAN §3 idea #8, brief §8.9).
 *
 * PURE by design: no DB, no Eloquent, no facades, no AI. Given typed nutrient
 * values and a quantity, it computes contributions and totals with reproducible
 * arithmetic. The brief forbids the LLM from ever doing this maths, so this
 * class is the single source of truth for it and is exhaustively unit-tested.
 *
 * Rounding policy: full floating precision is preserved through every
 * intermediate step; callers round once at the edge (persist/present) via
 * {@see NutrientValues::rounded()}. Nothing here rounds internally, so
 * conversions and sums never drift.
 *
 * Unknown nutrients (brief §2.1): a nutrient may be `null` ("not stated" — Open
 * Food Facts and label OCR routinely omit fibre/salt). This engine never
 * fabricates 0 for an unknown; the null propagates through {@see NutrientValues}
 * (scaling/summing an unknown stays unknown), so a total that includes a product
 * with an unstated nutrient reports that nutrient as unknown rather than
 * understated.
 */
class NutritionCalculator
{
    /**
     * Convert a set of nutrient values between per-100(g/ml) and per-serving
     * bases, preserving the figures' meaning (brief §7.12).
     *
     * @param  MeasuredAmount|null  $servingSize  the serving as a real mass or
     *                                            volume. Required whenever the two
     *                                            bases differ.
     *
     * @throws InvalidArgumentException when the serving size is needed but
     *                                  missing.
     */
    public function convertBasis(
        NutrientValues $values,
        ServingBasis $from,
        ServingBasis $to,
        ?MeasuredAmount $servingSize,
    ): NutrientValues {
        if ($from === $to) {
            return $values;
        }

        $serving = $this->requireServingSize($servingSize, 'convert between per-100 and per-serving bases');

        // per_100g → per_serving: one serving is `serving/100` of 100g.
        // per_serving → per_100g: 100g is `100/serving` servings.
        $factor = $from === ServingBasis::Per100g
            ? $serving / 100.0
            : 100.0 / $serving;

        return $values->scale($factor);
    }

    /**
     * Compute the nutrient contribution of consuming `$quantity $unit` of a
     * product version whose figures are stored on `$basis`.
     *
     * The version's figures are first normalised to a per-100(g/ml) baseline,
     * then scaled by the number of grams/ml actually consumed. This keeps a
     * single, auditable code path for every unit.
     *
     * @param  NutrientValues  $values  the version's stored nutrient figures.
     * @param  ServingBasis  $basis  the basis those figures are quoted on.
     * @param  MeasuredAmount|null  $servingSize  the stated serving. Required for
     *                                            per-serving figures, and for
     *                                            `unit`/`portion` consumption of
     *                                            per-100 figures.
     * @param  float  $quantity  how much was consumed (in `$unit`).
     * @param  QuantityUnit  $unit  the unit `$quantity` is expressed in.
     * @param  MeasuredAmount|null  $packSize  the contents of one whole pack.
     *                                         Required only when `$unit` is `pack`.
     *
     * @throws InvalidArgumentException on negative quantity or a missing size
     *                                  the chosen unit requires.
     */
    public function contribution(
        NutrientValues $values,
        ServingBasis $basis,
        ?MeasuredAmount $servingSize,
        float $quantity,
        QuantityUnit $unit,
        ?MeasuredAmount $packSize = null,
    ): NutrientValues {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Consumed quantity cannot be negative.');
        }

        if ($quantity === 0.0) {
            return NutrientValues::zero();
        }

        // Normalise the stored figures to a per-100(g/ml) baseline.
        $per100 = $basis === ServingBasis::Per100g
            ? $values
            : $this->convertBasis($values, ServingBasis::PerServing, ServingBasis::Per100g, $servingSize);

        // Resolve how many grams/ml the consumed quantity represents.
        $baseAmount = $this->baseAmountConsumed($quantity, $unit, $servingSize, $packSize);

        return $per100->scale($baseAmount / 100.0);
    }

    /**
     * Sum a list of nutrient contributions into a single total (meal or day
     * total). Full precision is preserved; round at the edge.
     *
     * Strict: a nutrient any contribution failed to state is unknown in the sum.
     * That is right for a MEAL, whose figures are recorded as one thing. For a
     * DAY — where one unknown item should not black out everything else logged —
     * use {@see total()}.
     *
     * @param  iterable<NutrientValues>  $contributions
     */
    public function sum(iterable $contributions): NutrientValues
    {
        $total = NutrientValues::zero();

        foreach ($contributions as $contribution) {
            $total = $total->add($contribution);
        }

        return $total;
    }

    /**
     * Total a list of contributions while keeping the gaps countable: both the
     * strict sum and the sum of what is actually known, with how many
     * contributions each nutrient is missing.
     *
     * @param  iterable<NutrientValues>  $contributions
     */
    public function total(iterable $contributions): NutrientTotal
    {
        return NutrientTotal::of($contributions);
    }

    /**
     * Translate a `$quantity $unit` into a number of grams/ml, using the
     * serving/pack sizes where the unit requires them.
     *
     * Grams and millilitres are interchangeable at this boundary because the
     * nutrition basis itself is "per 100 g OR 100 ml" — a product is quoted on
     * one or the other, never both, so one hundred base units is one hundred
     * base units. What is NOT interchangeable is a size that is not a mass or a
     * volume at all, and {@see MeasuredAmount} makes that unrepresentable rather
     * than merely discouraged (audit D5).
     */
    private function baseAmountConsumed(
        float $quantity,
        QuantityUnit $unit,
        ?MeasuredAmount $servingSize,
        ?MeasuredAmount $packSize,
    ): float {
        return match ($unit) {
            // Already a mass/volume.
            QuantityUnit::Gram, QuantityUnit::Millilitre => $quantity,

            // A whole item / portion == one stated serving.
            QuantityUnit::Unit, QuantityUnit::Portion => $quantity * $this->requireServingSize(
                $servingSize,
                'consume by unit/portion',
            ),

            // A whole pack.
            QuantityUnit::Pack => $quantity * $this->require(
                $packSize,
                'A pack size in grams or millilitres is required to consume by pack.',
            ),
        };
    }

    private function requireServingSize(?MeasuredAmount $servingSize, string $action): float
    {
        return $this->require(
            $servingSize,
            "A serving size in grams or millilitres is required to {$action}.",
        );
    }

    private function require(?MeasuredAmount $amount, string $message): float
    {
        if ($amount === null) {
            throw new InvalidArgumentException($message);
        }

        return $amount->inBaseUnit();
    }
}
