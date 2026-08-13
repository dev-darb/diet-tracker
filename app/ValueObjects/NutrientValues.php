<?php

namespace App\ValueObjects;

/**
 * An immutable set of nutrient values for one "unit" of measure (per 100g, per
 * serving, or an already-computed contribution) — BUILD_PLAN §3 idea #8, §5.
 *
 * This is a pure value object: no framework, no DB, no facades. It carries the
 * eight macro columns tracked across the app and knows how to scale and add
 * itself. Full floating precision is kept through every operation; rounding
 * happens ONLY at the edges via {@see rounded()} so intermediate arithmetic
 * never accumulates rounding drift (brief §8.9 — the maths must be provably
 * deterministic; the LLM never does it).
 *
 * ## Unknown vs zero (Milestone 2, brief §2.1)
 * A nutrient may be genuinely **zero** or **unknown**. Open Food Facts and label
 * OCR often omit fields (fibre and salt especially); recording those as 0 would
 * be a false claim. So a nutrient is `?float`: `null` means "not stated" and is
 * NEVER treated as 0. The arithmetic PROPAGATES unknowns — scaling an unknown
 * stays unknown, and adding an unknown to anything yields an unknown for that
 * nutrient (an honest total can't include a figure we don't have). An explicit
 * numeric 0.0 is a real, known zero and behaves normally.
 *
 * The constructor still defaults every nutrient to a known 0.0, so callers that
 * build a value from a few known fields (and {@see zero()}, the additive
 * identity) keep their existing meaning. Unknowns enter only when a caller
 * passes `null` explicitly or via {@see fromArray()} reading a null DB column.
 *
 * Units follow the product data convention: calories in kcal; protein, carbs,
 * sugars, fat, saturated_fat, fibre in grams; salt in grams.
 */
final class NutrientValues
{
    /** The nutrient keys, in canonical order, shared with the DB columns. */
    public const KEYS = [
        'calories',
        'protein',
        'carbs',
        'sugars',
        'fat',
        'saturated_fat',
        'fibre',
        'salt',
    ];

    public function __construct(
        public readonly ?float $calories = 0.0,
        public readonly ?float $protein = 0.0,
        public readonly ?float $carbs = 0.0,
        public readonly ?float $sugars = 0.0,
        public readonly ?float $fat = 0.0,
        public readonly ?float $saturatedFat = 0.0,
        public readonly ?float $fibre = 0.0,
        public readonly ?float $salt = 0.0,
    ) {}

    /**
     * Build from an associative array keyed by {@see KEYS}. A key that is
     * ABSENT defaults to a known 0.0 (backward-compatible with partial
     * construction); a key that is PRESENT-BUT-NULL is preserved as `null`
     * (unknown) — the distinction Eloquent's nullable decimal columns rely on.
     * Numeric strings (Eloquent decimal casts) and ints are coerced to float.
     *
     * @param  array<string, int|float|string|null>  $values
     */
    public static function fromArray(array $values): self
    {
        $get = static function (string $key) use ($values): ?float {
            if (! array_key_exists($key, $values)) {
                return 0.0;
            }

            return $values[$key] === null ? null : (float) $values[$key];
        };

        return new self(
            calories: $get('calories'),
            protein: $get('protein'),
            carbs: $get('carbs'),
            sugars: $get('sugars'),
            fat: $get('fat'),
            saturatedFat: $get('saturated_fat'),
            fibre: $get('fibre'),
            salt: $get('salt'),
        );
    }

    /** The additive identity — a set of all-zero (known) nutrients. */
    public static function zero(): self
    {
        return new self;
    }

    /** Multiply every nutrient by a factor, returning a new value object. Unknowns stay unknown. */
    public function scale(float $factor): self
    {
        $scale = static fn (?float $v): ?float => $v === null ? null : $v * $factor;

        return new self(
            calories: $scale($this->calories),
            protein: $scale($this->protein),
            carbs: $scale($this->carbs),
            sugars: $scale($this->sugars),
            fat: $scale($this->fat),
            saturatedFat: $scale($this->saturatedFat),
            fibre: $scale($this->fibre),
            salt: $scale($this->salt),
        );
    }

    /**
     * Add another set of nutrients component-wise, returning a new object.
     * Unknowns PROPAGATE: if either operand is unknown for a nutrient, the sum
     * is unknown for that nutrient — a total must not silently absorb a figure
     * we never had (brief §2.1).
     */
    public function add(self $other): self
    {
        $add = static fn (?float $a, ?float $b): ?float => ($a === null || $b === null) ? null : $a + $b;

        return new self(
            calories: $add($this->calories, $other->calories),
            protein: $add($this->protein, $other->protein),
            carbs: $add($this->carbs, $other->carbs),
            sugars: $add($this->sugars, $other->sugars),
            fat: $add($this->fat, $other->fat),
            saturatedFat: $add($this->saturatedFat, $other->saturatedFat),
            fibre: $add($this->fibre, $other->fibre),
            salt: $add($this->salt, $other->salt),
        );
    }

    /**
     * Round every nutrient to the given precision. This is the ONLY place
     * rounding happens; call it once when persisting or presenting a total.
     * Unknowns stay unknown.
     */
    public function rounded(int $precision = 2): self
    {
        $round = static fn (?float $v): ?float => $v === null ? null : round($v, $precision);

        return new self(
            calories: $round($this->calories),
            protein: $round($this->protein),
            carbs: $round($this->carbs),
            sugars: $round($this->sugars),
            fat: $round($this->fat),
            saturatedFat: $round($this->saturatedFat),
            fibre: $round($this->fibre),
            salt: $round($this->salt),
        );
    }

    /** Whether a given nutrient key currently holds a known value (not unknown). */
    public function isKnown(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** Whether every tracked nutrient holds a known value. */
    public function isComplete(): bool
    {
        foreach (self::KEYS as $key) {
            if ($this->get($key) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The nutrient keys whose value is unknown (not stated).
     *
     * @return array<int, string>
     */
    public function unknownKeys(): array
    {
        return array_values(array_filter(self::KEYS, fn (string $key): bool => $this->get($key) === null));
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
            default => null,
        };
    }

    /**
     * Export as an associative array keyed by {@see KEYS} — ready to persist to
     * the snapshot/total columns. Unknown nutrients export as `null`. Optionally
     * rounded first.
     *
     * @return array<string, float|null>
     */
    public function toArray(?int $precision = null): array
    {
        $values = $precision === null ? $this : $this->rounded($precision);

        return [
            'calories' => $values->calories,
            'protein' => $values->protein,
            'carbs' => $values->carbs,
            'sugars' => $values->sugars,
            'fat' => $values->fat,
            'saturated_fat' => $values->saturatedFat,
            'fibre' => $values->fibre,
            'salt' => $values->salt,
        ];
    }
}
