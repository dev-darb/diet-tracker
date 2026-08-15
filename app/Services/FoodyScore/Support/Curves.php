<?php

namespace App\Services\FoodyScore\Support;

/**
 * Foody Score v1 smooth scoring curves (spec §5, §9). Pure functions — the
 * whole engine's arithmetic character lives here, deterministic by
 * construction. No randomness, no time, no I/O.
 */
final class Curves
{
    /**
     * Plateau curve (spec §5): full credit inside [lowerFull, upperFull] on
     * ratio r = actual/target; Gaussian falloff outside with per-side sigmas.
     *
     * @return float 0–100
     */
    public static function plateau(float $ratio, float $lowerFull, float $upperFull, float $sigmaLow, float $sigmaHigh): float
    {
        if ($ratio >= $lowerFull && $ratio <= $upperFull) {
            return 100.0;
        }

        if ($ratio < $lowerFull) {
            $z = ($ratio - $lowerFull) / $sigmaLow;
        } else {
            $z = ($ratio - $upperFull) / $sigmaHigh;
        }

        return self::clamp(100.0 * exp(-0.5 * $z * $z));
    }

    /**
     * Upper-limit curve (spec §11): at or below the limit is full credit —
     * staying low is sufficient, and nothing ever rewards consuming more.
     */
    public static function upperLimit(float $ratio, float $fullBelow, float $sigmaHigh): float
    {
        if ($ratio <= $fullBelow) {
            return 100.0;
        }

        $z = ($ratio - $fullBelow) / $sigmaHigh;

        return self::clamp(100.0 * exp(-0.5 * $z * $z));
    }

    /**
     * Saturating fibre adequacy (spec §9): ~80% of target is good, 100% is
     * full credit, additional intake adds nothing (and costs nothing).
     */
    public static function fibreSaturation(float $ratio, float $k = 3.5): float
    {
        if ($ratio <= 0) {
            return 0.0;
        }

        $score = 100.0 * (1 - exp(-$k * min($ratio, 1.0))) / (1 - exp(-$k));

        return min(100.0, $score);
    }

    /**
     * Diminishing-returns credit toward an aspirational benchmark (spec §8:
     * "30 plants/week" — reward improvement below, diminish above).
     */
    public static function benchmarkProgress(float $count, float $benchmark): float
    {
        if ($count <= 0) {
            return 0.0;
        }

        if ($count >= $benchmark) {
            // Full credit at the benchmark; gentle asymptotic extra up to +5%
            // of the range is absorbed by the cap — no >100 scoring.
            return 100.0;
        }

        // Smooth concave rise: early plants are worth more than the 29th.
        return self::clamp(100.0 * (1 - pow(1 - ($count / $benchmark), 1.6)));
    }

    public static function clamp(float $value, float $min = 0.0, float $max = 100.0): float
    {
        return max($min, min($max, $value));
    }

    /**
     * Smoothstep between two edges — used for the today-vs-rolling display
     * blend (spec §12: today weight rises smoothly with day completion).
     */
    public static function smoothstep(float $edge0, float $edge1, float $x): float
    {
        if ($edge1 <= $edge0) {
            return $x >= $edge1 ? 1.0 : 0.0;
        }

        $t = max(0.0, min(1.0, ($x - $edge0) / ($edge1 - $edge0)));

        return $t * $t * (3 - 2 * $t);
    }
}
