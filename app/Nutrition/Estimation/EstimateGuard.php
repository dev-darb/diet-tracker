<?php

namespace App\Nutrition\Estimation;

use App\Nutrition\NutrientRegistry;
use App\Nutrition\NutritionSanityCheck;
use App\ValueObjects\NutrientValues;

/**
 * The acceptance criteria a model's proposal has to meet before any of it counts
 * as a figure (founder decision, Aug 2026).
 *
 * A model may produce nutrition — inside guardrails. This class is the
 * guardrails. Everything it does is deterministic; the model gets no say in
 * whether its own answer is good enough.
 *
 * ## The criteria
 *  1. It must have shown its working. A number with no reasoning cannot be
 *     checked by anyone later, so a draft with no steps is rejected outright
 *     rather than merely distrusted.
 *  2. It must name what it reasoned from — a reference food, a published menu,
 *     a typical composition.
 *  3. It must clear a confidence floor. A model that says it is guessing is
 *     believed.
 *  4. Only nutrients that were ASKED FOR survive. Anything else is dropped, so
 *     an estimate cannot spread into fields nobody wanted.
 *  5. Every figure must be physically possible for its nutrient.
 *  6. The set has to hold together: the same cross-checks a real source faces —
 *     energy consistent with its macros, sugars within carbohydrate, a sane mass
 *     budget. An estimate that contradicts itself is worth less than no estimate.
 *
 * A failure at 1, 2, 3 or 6 rejects the WHOLE draft. Those are failures of the
 * reasoning, and salvaging individual numbers out of reasoning we have decided
 * not to trust would be the worst of both worlds. Failures at 4 and 5 drop the
 * offending nutrient and let the rest stand.
 */
final class EstimateGuard
{
    /** Below this the model is telling us it is guessing, and we believe it. */
    public const CONFIDENCE_FLOOR = 0.35;

    /**
     * How far past a nutrient's per-100g ceiling a whole-item figure may go.
     * A whole dish is not 100 g — a large takeaway can carry 1,500 kcal, which is
     * well past the 900 kcal/100g ceiling and entirely real. The multiplier keeps
     * the check meaningful without it firing on every large meal.
     */
    private const WHOLE_ITEM_ALLOWANCE = 30.0;

    public function assess(EstimationRequest $request, ?EstimationDraft $draft): EstimateVerdict
    {
        if ($draft === null) {
            return EstimateVerdict::rejected(['The estimator returned nothing.']);
        }

        $blocking = [];

        if ($draft->steps === []) {
            $blocking[] = 'No reasoning was given, so the figures cannot be checked by anyone later.';
        }

        if ($draft->reference === null) {
            $blocking[] = 'No reference was named for the estimate to be based on.';
        }

        if ($draft->confidence < self::CONFIDENCE_FLOOR) {
            $blocking[] = sprintf(
                'Stated confidence %.2f is below the floor of %.2f.',
                $draft->confidence,
                self::CONFIDENCE_FLOOR,
            );
        }

        if ($blocking !== []) {
            return EstimateVerdict::rejected($blocking);
        }

        [$accepted, $dropped] = $this->filterValues($request, $draft);

        if ($accepted === []) {
            return EstimateVerdict::rejected(
                array_merge(['Nothing usable survived: no requested nutrient came back with a plausible figure.'], $dropped),
            );
        }

        // The same cross-checks a real source faces. On a per-100 basis they
        // apply directly; on a whole item the mass budget is meaningless (a
        // 400 g plate legitimately holds more than 100 g of food), so only the
        // internally-relative checks are worth running.
        $findings = $this->crossCheck($request, $accepted);

        if ($findings !== []) {
            return EstimateVerdict::rejected(array_merge(
                ['The figures do not hold together.'],
                $findings,
            ));
        }

        return EstimateVerdict::accepted($accepted, $dropped);
    }

    /**
     * Keep only what was asked for and is physically possible.
     *
     * @return array{0: array<string, float>, 1: array<int, string>}
     */
    private function filterValues(EstimationRequest $request, EstimationDraft $draft): array
    {
        $accepted = [];
        $dropped = [];

        foreach ($draft->values as $key => $value) {
            if ($value === null) {
                // The model declining to guess is a legitimate answer, and a
                // quiet one — nothing to report.
                continue;
            }

            if (! $request->wants($key)) {
                $dropped[] = "{$key}: not asked for.";

                continue;
            }

            $nutrient = NutrientRegistry::get($key);

            if ($nutrient === null) {
                $dropped[] = "{$key}: not a tracked nutrient.";

                continue;
            }

            $ceiling = $request->basis === EstimationBasis::Per100
                ? $nutrient->maxPer100
                : $nutrient->maxPer100 * self::WHOLE_ITEM_ALLOWANCE;

            if (! is_finite($value) || $value < 0.0 || $value > $ceiling) {
                $dropped[] = sprintf('%s: %.2f is outside what is physically possible.', $key, $value);

                continue;
            }

            $accepted[$key] = $nutrient->round($value);
        }

        return [$accepted, $dropped];
    }

    /**
     * @param  array<string, float>  $accepted
     * @return array<int, string>
     */
    private function crossCheck(EstimationRequest $request, array $accepted): array
    {
        $values = NutrientValues::fromStated($accepted);

        if ($request->basis === EstimationBasis::Per100) {
            return NutritionSanityCheck::inspect($values);
        }

        // Whole-item: scale to a nominal 100 g so the energy-versus-macros check
        // still applies (it is a ratio, so the scale cancels), and skip the mass
        // budget, which only means something per 100 g.
        return array_values(array_filter(
            NutritionSanityCheck::inspect($values),
            static fn (string $finding): bool => ! str_contains($finding, 'more than the food weighs'),
        ));
    }
}
