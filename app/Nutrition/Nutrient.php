<?php

namespace App\Nutrition;

use App\ValueObjects\NutrientValues;

/**
 * One tracked nutrient, described once (audit D1–D3, Aug 2026).
 *
 * Before this existed, every nutrient was hard-coded in at least five places —
 * the value object's key list, the Open Food Facts mapper, three migrations, the
 * admin form and the display layer. Adding fibre meant editing all of them;
 * adding fifteen micronutrients would have meant editing all of them fifteen
 * times, and the audit found real bugs that came from exactly that duplication
 * (energy read only as kcal, salt read only as salt).
 *
 * So a nutrient declares itself here, once, and {@see NutrientRegistry} is the
 * single list everything else reads.
 *
 * ## Sources are an ordered map, not a single key
 * `$sources` maps an Open Food Facts nutriment key to the multiplier that turns
 * its figure into this nutrient's canonical unit, in PREFERENCE order. That one
 * shape covers three separate problems the audit found:
 *
 *   - unit conversion — OFF states minerals in grams, we store milligrams
 *     (`'iron_100g' => 1000.0`);
 *   - unit-of-measure fallback — energy stated only in kilojoules is still
 *     energy (`'energy-kj_100g' => 0.239006`);
 *   - compound fallback — salt stated only as sodium is still salt
 *     (`'sodium_100g' => 2.5`).
 *
 * The first key present in the payload wins, and the caller is told WHICH key
 * answered, so provenance can record that a figure was derived rather than
 * stated. A derived figure is real data; a fabricated one is not, and this is
 * the line between them.
 */
final class Nutrient
{
    /**
     * @param  string  $key  the database column and {@see NutrientValues} key.
     * @param  array<string, float>  $sources  OFF nutriment BASE name (no `_100g` /
     *                                         `_serving` suffix) => multiplier into
     *                                         $unit, in preference order.
     * @param  float  $maxPer100  the largest per-100g/ml figure that is physically
     *                            possible, in $unit. OFF is crowd-sourced and full of
     *                            data-entry errors; beyond this a value is treated as
     *                            unknown rather than stored (never clamped — a clamped
     *                            figure would be a fabricated one).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly NutrientUnit $unit,
        public readonly NutrientGroup $group,
        public readonly array $sources,
        public readonly float $maxPer100,
    ) {}

    /**
     * Read this nutrient out of an Open Food Facts `nutriments` payload, as a
     * per-100g/ml figure in this nutrient's canonical unit.
     *
     * Returns null when OFF states nothing usable — never 0, which would be a
     * false nutritional claim (brief §2.1). Returns the converted figure, the
     * exact payload key that answered, and whether reaching it required a
     * fallback, so provenance can say a figure was derived rather than stated.
     *
     * @param  array<string, mixed>  $nutriments
     * @param  string  $suffix  `_100g` normally; `_serving` for the per-serving
     *                          fallback, where $scale renormalises to per-100.
     * @param  float  $scale  applied after the unit multiplier (100 / serving grams).
     * @return array{value: float, source: string, derived: bool}|null
     */
    public function readFrom(array $nutriments, string $suffix = '_100g', float $scale = 1.0): ?array
    {
        $preferred = array_key_first($this->sources);

        foreach ($this->sources as $base => $multiplier) {
            $sourceKey = $base.$suffix;

            if (! array_key_exists($sourceKey, $nutriments)) {
                continue;
            }

            $raw = $nutriments[$sourceKey];

            if (! is_numeric($raw)) {
                continue;
            }

            $value = ((float) $raw) * $multiplier * $scale;

            // A value outside physical possibility is a data-entry error, not a
            // measurement. Keep looking — a later source may be sane.
            if (! is_finite($value) || $value < 0.0 || $value > $this->maxPer100) {
                continue;
            }

            return [
                'value' => $value,
                'source' => $sourceKey,
                'derived' => $base !== $preferred || $suffix !== '_100g',
            ];
        }

        return null;
    }

    /** Round a figure to the precision its unit is stored at. */
    public function round(float $value): float
    {
        return round($value, $this->unit->precision());
    }
}
