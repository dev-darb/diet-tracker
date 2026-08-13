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
        public readonly float $calories = 0.0,
        public readonly float $protein = 0.0,
        public readonly float $carbs = 0.0,
        public readonly float $sugars = 0.0,
        public readonly float $fat = 0.0,
        public readonly float $saturatedFat = 0.0,
        public readonly float $fibre = 0.0,
        public readonly float $salt = 0.0,
    ) {}

    /**
     * Build from an associative array keyed by {@see KEYS} (missing keys → 0).
     * Values may be int, float, or numeric string (Eloquent decimal casts).
     *
     * @param  array<string, int|float|string|null>  $values
     */
    public static function fromArray(array $values): self
    {
        $get = static fn (string $key): float => isset($values[$key]) ? (float) $values[$key] : 0.0;

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

    /** The additive identity — a set of all-zero nutrients. */
    public static function zero(): self
    {
        return new self;
    }

    /** Multiply every nutrient by a factor, returning a new value object. */
    public function scale(float $factor): self
    {
        return new self(
            calories: $this->calories * $factor,
            protein: $this->protein * $factor,
            carbs: $this->carbs * $factor,
            sugars: $this->sugars * $factor,
            fat: $this->fat * $factor,
            saturatedFat: $this->saturatedFat * $factor,
            fibre: $this->fibre * $factor,
            salt: $this->salt * $factor,
        );
    }

    /** Add another set of nutrients component-wise, returning a new object. */
    public function add(self $other): self
    {
        return new self(
            calories: $this->calories + $other->calories,
            protein: $this->protein + $other->protein,
            carbs: $this->carbs + $other->carbs,
            sugars: $this->sugars + $other->sugars,
            fat: $this->fat + $other->fat,
            saturatedFat: $this->saturatedFat + $other->saturatedFat,
            fibre: $this->fibre + $other->fibre,
            salt: $this->salt + $other->salt,
        );
    }

    /**
     * Round every nutrient to the given precision. This is the ONLY place
     * rounding happens; call it once when persisting or presenting a total.
     */
    public function rounded(int $precision = 2): self
    {
        return new self(
            calories: round($this->calories, $precision),
            protein: round($this->protein, $precision),
            carbs: round($this->carbs, $precision),
            sugars: round($this->sugars, $precision),
            fat: round($this->fat, $precision),
            saturatedFat: round($this->saturatedFat, $precision),
            fibre: round($this->fibre, $precision),
            salt: round($this->salt, $precision),
        );
    }

    /**
     * Export as an associative array keyed by {@see KEYS} — ready to persist to
     * the snapshot/total columns. Optionally rounded first.
     *
     * @return array<string, float>
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
