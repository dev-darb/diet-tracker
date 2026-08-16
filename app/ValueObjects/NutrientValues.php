<?php

namespace App\ValueObjects;

use App\Nutrition\NutrientGroup;
use App\Nutrition\NutrientRegistry;

/**
 * An immutable set of nutrient values for one "unit" of measure (per 100g, per
 * serving, or an already-computed contribution) — BUILD_PLAN §3 idea #8, §5.
 *
 * This is a pure value object: no framework, no DB, no facades. It carries every
 * nutrient {@see NutrientRegistry} declares and knows how to scale and add
 * itself. Full floating precision is kept through every operation; rounding
 * happens ONLY at the edges via {@see rounded()} so intermediate arithmetic
 * never accumulates rounding drift (brief §8.9 — the maths must be provably
 * deterministic; the LLM never does it).
 *
 * ## Unknown vs zero (Milestone 2, brief §2.1)
 * A nutrient may be genuinely **zero** or **unknown**. Open Food Facts and label
 * OCR often omit fields (fibre, salt and every micronutrient especially);
 * recording those as 0 would be a false claim. So a nutrient is `?float`: `null`
 * means "not stated" and is NEVER treated as 0. The arithmetic PROPAGATES
 * unknowns — scaling an unknown stays unknown, and adding an unknown to anything
 * yields an unknown for that nutrient. An explicit numeric 0.0 is a real, known
 * zero and behaves normally.
 *
 * ## Two shapes of nutrient, two meanings of "absent"
 * The eight macros are promoted constructor properties, defaulting to a known
 * 0.0, because callers have always built partial values from a few known fields
 * and meant "the rest contribute nothing" ({@see zero()} is the additive
 * identity that relies on it).
 *
 * The micronutrients (Aug 2026) were never part of that contract and are sparse
 * by nature, so an absent micro means UNKNOWN, not zero. Getting this backwards
 * would have every hand-built value silently claim a product contains no iron.
 * {@see zero()} still sets them to a known 0.0 — an empty plate really does
 * contain none of anything — which keeps it a true additive identity for sums.
 *
 * Units follow {@see NutrientRegistry}: energy in kcal, macros in grams,
 * minerals and vitamins in milligrams or micrograms as their label states them.
 */
final class NutrientValues
{
    /**
     * Every nutrient key, in canonical order — the shape of a nutrition row.
     *
     * Mirrors {@see NutrientRegistry::keys()}, which stays the source of truth
     * for units, sources, ranges and precision. A const is kept here because
     * this list is read in `$model->only(...)` calls across the app; the pair is
     * held together by a parity test, so adding a nutrient to the registry and
     * forgetting this list fails the suite immediately.
     */
    public const KEYS = [
        'calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt',
        'iron', 'calcium', 'potassium', 'magnesium', 'zinc', 'phosphorus', 'iodine', 'selenium',
        'vitamin_a', 'vitamin_c', 'vitamin_d', 'vitamin_e', 'vitamin_b6', 'vitamin_b12', 'folate',
    ];

    /** The eight the app has always tracked — the "is this row usable at all" set. */
    public const MACRO_KEYS = [
        'calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt',
    ];

    /**
     * Micronutrient figures, keyed by registry key. Every micro key is always
     * present after construction; the value is `null` when unknown.
     *
     * @var array<string, float|null>
     */
    private readonly array $micros;

    /**
     * @param  array<string, float|int|string|null>  $micros  any subset of the micro
     *                                                        keys; anything absent is unknown.
     */
    public function __construct(
        public readonly ?float $calories = 0.0,
        public readonly ?float $protein = 0.0,
        public readonly ?float $carbs = 0.0,
        public readonly ?float $sugars = 0.0,
        public readonly ?float $fat = 0.0,
        public readonly ?float $saturatedFat = 0.0,
        public readonly ?float $fibre = 0.0,
        public readonly ?float $salt = 0.0,
        array $micros = [],
    ) {
        $normalised = [];

        foreach (NutrientRegistry::microKeys() as $key) {
            $value = $micros[$key] ?? null;
            $normalised[$key] = $value === null ? null : (float) $value;
        }

        $this->micros = $normalised;
    }

    /**
     * Build from an associative array keyed by {@see KEYS}.
     *
     * A MACRO key that is ABSENT defaults to a known 0.0 (backward-compatible
     * partial construction); a MICRO key that is absent is unknown. A key that is
     * PRESENT-BUT-NULL is preserved as `null` either way — the distinction
     * Eloquent's nullable decimal columns rely on. Numeric strings (Eloquent
     * decimal casts) and ints are coerced to float.
     *
     * Reading a database row hands every column in, so both rules agree there.
     * When you are building a value by hand and "absent" should mean unknown for
     * everything, use {@see fromStated()} instead.
     *
     * @param  array<string, int|float|string|null>  $values
     */
    public static function fromArray(array $values): self
    {
        return self::build($values, macroDefault: 0.0);
    }

    /**
     * Build from an array where ONLY what is present is known — an absent key is
     * unknown, macro or micro, with no partial-construction convenience.
     *
     * This is the constructor for anything assembling nutrition from an outside
     * source: an estimate, a resolved meal component, a reconciliation answer. It
     * exists so a writer that simply doesn't have a figure cannot silently claim
     * the food contains none of it.
     *
     * @param  array<string, int|float|string|null>  $values
     */
    public static function fromStated(array $values): self
    {
        return self::build($values, macroDefault: null);
    }

    /**
     * @param  array<string, int|float|string|null>  $values
     */
    private static function build(array $values, ?float $macroDefault): self
    {
        $get = static function (string $key) use ($values, $macroDefault): ?float {
            if (! array_key_exists($key, $values)) {
                return $macroDefault;
            }

            return $values[$key] === null ? null : (float) $values[$key];
        };

        $micros = [];

        foreach (NutrientRegistry::microKeys() as $key) {
            // Absent micro is always unknown — see the class docblock.
            $micros[$key] = array_key_exists($key, $values) && $values[$key] !== null
                ? (float) $values[$key]
                : null;
        }

        return new self(
            calories: $get('calories'),
            protein: $get('protein'),
            carbs: $get('carbs'),
            sugars: $get('sugars'),
            fat: $get('fat'),
            saturatedFat: $get('saturated_fat'),
            fibre: $get('fibre'),
            salt: $get('salt'),
            micros: $micros,
        );
    }

    /**
     * The additive identity — a set of all-zero (known) nutrients, micros
     * included. Summing starts here, so the zeros must be known or every total's
     * micronutrients would collapse to unknown.
     */
    public static function zero(): self
    {
        return new self(micros: array_fill_keys(NutrientRegistry::microKeys(), 0.0));
    }

    /**
     * A set where every nutrient is UNKNOWN (not stated) — never a fabricated 0.
     * Used when a consumption snapshot has no usable product version, or the
     * chosen unit needs a serving/pack size the version lacks, so the honest
     * record is "we don't know" rather than an understated total (brief §2.1).
     */
    public static function unknown(): self
    {
        return new self(null, null, null, null, null, null, null, null);
    }

    /** Multiply every nutrient by a factor, returning a new value object. Unknowns stay unknown. */
    public function scale(float $factor): self
    {
        return $this->mapAll(static fn (?float $v): ?float => $v === null ? null : $v * $factor);
    }

    /**
     * Add another set of nutrients component-wise, returning a new object.
     * Unknowns PROPAGATE: if either operand is unknown for a nutrient, the sum
     * is unknown for that nutrient — a total must not silently absorb a figure
     * we never had (brief §2.1).
     */
    public function add(self $other): self
    {
        return $this->zipAll(
            $other,
            static fn (?float $a, ?float $b): ?float => ($a === null || $b === null) ? null : $a + $b,
        );
    }

    /**
     * Round every nutrient. Macros use the given precision (the app's long-
     * standing 2dp persistence rule); micronutrients always use their own unit's
     * precision, because a microgram figure rounded to 2dp is a zero.
     *
     * This is the ONLY place rounding happens; call it once when persisting or
     * presenting a total. Unknowns stay unknown.
     */
    public function rounded(int $precision = 2): self
    {
        $round = static fn (?float $v, int $places): ?float => $v === null ? null : round($v, $places);

        $micros = [];

        foreach ($this->micros as $key => $value) {
            $micros[$key] = $round($value, NutrientRegistry::get($key)?->unit->precision() ?? $precision);
        }

        return new self(
            calories: $round($this->calories, $precision),
            protein: $round($this->protein, $precision),
            carbs: $round($this->carbs, $precision),
            sugars: $round($this->sugars, $precision),
            fat: $round($this->fat, $precision),
            saturatedFat: $round($this->saturatedFat, $precision),
            fibre: $round($this->fibre, $precision),
            salt: $round($this->salt, $precision),
            micros: $micros,
        );
    }

    /** Whether a given nutrient key currently holds a known value (not unknown). */
    public function isKnown(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Whether every MACRO holds a known value. Micronutrients are excluded
     * deliberately: Open Food Facts states them for a minority of products, so a
     * micro-inclusive "complete" would be false for almost every real food and
     * would say nothing useful. Use {@see coverage()} for the honest breadth
     * figure.
     */
    public function isComplete(): bool
    {
        foreach (self::MACRO_KEYS as $key) {
            if ($this->get($key) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The MACRO keys whose value is unknown (not stated) — what a day or product
     * readout names when it shows `----`.
     *
     * @return array<int, string>
     */
    public function unknownKeys(): array
    {
        return array_values(array_filter(self::MACRO_KEYS, fn (string $key): bool => $this->get($key) === null));
    }

    /**
     * The micronutrient keys whose value is unknown.
     *
     * @return array<int, string>
     */
    public function unknownMicroKeys(): array
    {
        return array_keys(array_filter($this->micros, static fn (?float $v): bool => $v === null));
    }

    /**
     * How much of the tracked nutrition this set actually states, as a 0..1
     * fraction — the honest breadth figure behind Foody Score's
     * `nutrient_coverage` confidence.
     *
     * @param  NutrientGroup|null  $group  restrict to macros or micros; null covers both.
     */
    public function coverage(?NutrientGroup $group = null): float
    {
        $keys = match ($group) {
            NutrientGroup::Macro => self::MACRO_KEYS,
            NutrientGroup::Micro => NutrientRegistry::microKeys(),
            default => self::KEYS,
        };

        if ($keys === []) {
            return 0.0;
        }

        $known = count(array_filter($keys, fn (string $key): bool => $this->get($key) !== null));

        return round($known / count($keys), 4);
    }

    /** Read a nutrient by its DB-column key. */
    public function get(string $key): ?float
    {
        return match ($key) {
            'calories' => $this->calories,
            'protein' => $this->protein,
            'carbs' => $this->carbs,
            'sugars' => $this->sugars,
            'fat' => $this->fat,
            'saturated_fat' => $this->saturatedFat,
            'fibre' => $this->fibre,
            'salt' => $this->salt,
            default => $this->micros[$key] ?? null,
        };
    }

    /**
     * Export as an associative array keyed by {@see KEYS} — ready to persist to
     * the snapshot/total columns. Unknown nutrients export as `null`. Optionally
     * rounded first (macros to `$precision`, micros to their unit's precision).
     *
     * @return array<string, float|null>
     */
    public function toArray(?int $precision = null): array
    {
        $values = $precision === null ? $this : $this->rounded($precision);

        $out = [];

        foreach (self::KEYS as $key) {
            $out[$key] = $values->get($key);
        }

        return $out;
    }

    /**
     * @param  callable(float|null): (float|null)  $fn
     */
    private function mapAll(callable $fn): self
    {
        return new self(
            calories: $fn($this->calories),
            protein: $fn($this->protein),
            carbs: $fn($this->carbs),
            sugars: $fn($this->sugars),
            fat: $fn($this->fat),
            saturatedFat: $fn($this->saturatedFat),
            fibre: $fn($this->fibre),
            salt: $fn($this->salt),
            micros: array_map($fn, $this->micros),
        );
    }

    /**
     * @param  callable(float|null, float|null): (float|null)  $fn
     */
    private function zipAll(self $other, callable $fn): self
    {
        $micros = [];

        foreach ($this->micros as $key => $value) {
            $micros[$key] = $fn($value, $other->get($key));
        }

        return new self(
            calories: $fn($this->calories, $other->calories),
            protein: $fn($this->protein, $other->protein),
            carbs: $fn($this->carbs, $other->carbs),
            sugars: $fn($this->sugars, $other->sugars),
            fat: $fn($this->fat, $other->fat),
            saturatedFat: $fn($this->saturatedFat, $other->saturatedFat),
            fibre: $fn($this->fibre, $other->fibre),
            salt: $fn($this->salt, $other->salt),
            micros: $micros,
        );
    }
}
