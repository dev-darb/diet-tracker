<?php

namespace App\ValueObjects;

/**
 * A running total that keeps its gaps visible instead of collapsing into one
 * (founder decision, Aug 2026).
 *
 * ## The problem it solves
 * {@see NutrientValues::add()} propagates unknowns, which is right for a single
 * food: a figure nobody stated cannot be summed into a figure we claim to know.
 * Applied to a whole DAY, though, the same rule blacks the day out — log a
 * coffee whose nutrition nobody knows and 1,850 honestly-recorded kilocalories
 * become `----`. The day then drops out of the score's evidence entirely, so
 * one unknown item costs more than it should.
 *
 * ## What it does instead
 * It keeps both readings. The strict total stays exactly as it was, and
 * alongside it sits the sum of everything that IS known, plus a count of how
 * many contributions that sum is missing. The day reads:
 *
 *     1,850 kcal   ·   6 of 7 items known
 *
 * which is more honest than `----`, not less: it states a real figure and states
 * what the figure is missing. What it must never do is present the partial sum
 * alone, as though it were the whole day — hence the contributor counts are part
 * of the object, not an optional extra a caller might forget to read.
 *
 * Immutable and pure; the arithmetic still lives in NutrientValues.
 */
final class NutrientTotal
{
    /**
     * @param  array<string, int>  $known  nutrient key => contributions that stated it.
     * @param  array<string, int>  $unknown  nutrient key => contributions that did not.
     */
    private function __construct(
        private readonly NutrientValues $strict,
        private readonly NutrientValues $partial,
        private readonly array $known,
        private readonly array $unknown,
    ) {}

    public static function empty(): self
    {
        return new self(
            NutrientValues::zero(),
            // The known sum starts UNKNOWN, not zero. "The sum of what is known"
            // has no value until something is known — and a day where nothing was
            // stated must read `----`, never a confident 0 kcal. That is the
            // fabricated zero this whole subsystem exists to prevent, and it
            // would sneak back in through the very object meant to be kinder
            // about gaps.
            NutrientValues::unknown(),
            array_fill_keys(NutrientValues::KEYS, 0),
            array_fill_keys(NutrientValues::KEYS, 0),
        );
    }

    /**
     * Total a list of contributions.
     *
     * @param  iterable<NutrientValues>  $contributions
     */
    public static function of(iterable $contributions): self
    {
        $total = self::empty();

        foreach ($contributions as $contribution) {
            $total = $total->add($contribution);
        }

        return $total;
    }

    public function add(NutrientValues $contribution): self
    {
        $known = $this->known;
        $unknown = $this->unknown;
        $partialValues = [];

        foreach (NutrientValues::KEYS as $key) {
            $value = $contribution->get($key);

            if ($value === null) {
                $unknown[$key]++;
                // The partial sum simply does not include what it does not have —
                // and if nothing has been stated yet it stays unknown, rather
                // than becoming a zero that looks like a measurement.
                $partialValues[$key] = $this->partial->get($key);

                continue;
            }

            $known[$key]++;
            $partialValues[$key] = ($this->partial->get($key) ?? 0.0) + $value;
        }

        return new self(
            $this->strict->add($contribution),
            NutrientValues::fromStated($partialValues),
            $known,
            $unknown,
        );
    }

    /**
     * The strict total: unknown for any nutrient a contribution did not state.
     * This is what a confidence calculation should read — it is the reading that
     * still admits the gap.
     */
    public function strict(): NutrientValues
    {
        return $this->strict;
    }

    /**
     * The sum of everything known. Real figures from real records — but only the
     * ones that had figures, so it is a floor, never a complete picture. Always
     * present it next to {@see missing()}.
     */
    public function known(): NutrientValues
    {
        return $this->partial;
    }

    /** How many contributions stated this nutrient. */
    public function knownCount(string $key): int
    {
        return $this->known[$key] ?? 0;
    }

    /** How many contributions did not state it — the size of the gap. */
    public function missing(string $key): int
    {
        return $this->unknown[$key] ?? 0;
    }

    /** Total contributions counted, stated or not. */
    public function contributors(string $key = 'calories'): int
    {
        return $this->knownCount($key) + $this->missing($key);
    }

    /** Whether every contribution stated this nutrient — the total is whole. */
    public function isComplete(string $key = 'calories'): bool
    {
        return $this->missing($key) === 0;
    }

    /**
     * The share of contributions that stated this nutrient, 0..1. One contributor
     * with nothing stated is 0.0; nothing counted at all is 0.0 too — an empty
     * day has no coverage to speak of.
     */
    public function coverage(string $key = 'calories'): float
    {
        $contributors = $this->contributors($key);

        return $contributors === 0 ? 0.0 : round($this->knownCount($key) / $contributors, 4);
    }
}
