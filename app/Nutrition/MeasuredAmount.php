<?php

namespace App\Nutrition;

/**
 * A real mass or volume — the only kind of amount nutrition arithmetic can use.
 *
 * ## Why this exists (audit D4, Aug 2026)
 * Pack and serving sizes used to be read with one regex that took the first
 * number-followed-by-a-word it could find. Against real Open Food Facts strings
 * that was wrong far more often than right:
 *
 *     "1 bar (48 g)"     -> 1 "bar"        (should be 48 g)
 *     "1 portion (125g)" -> 1 "portion"    (should be 125 g)
 *     "1kg"              -> 1 "kg"         (should be 1000 g)
 *     "6 x 25g"          -> 6 "x"          (should be 150 g)
 *
 * and nothing downstream checked the unit, so each of those became "1 gram".
 *
 * The parser here reads the string the way a person does: the amount in the
 * brackets is the real one, "6 x 25 g" is a multipack, and kilograms are
 * thousands of grams. When it cannot find a mass or a volume it returns null —
 * an unreadable pack size is unknown, never a number that happens to be wrong.
 */
final class MeasuredAmount
{
    /**
     * Beyond this a parsed figure is a data-entry error, not a grocery item
     * (100 kg / 100 litres). Also keeps the decimal columns in range.
     */
    private const MAX = 100_000.0;

    /** Written unit => [multiplier, base unit]. Ordered longest-first when matched. */
    private const CONVERSIONS = [
        'kg' => [1000.0, 'g'],
        'kgs' => [1000.0, 'g'],
        'g' => [1.0, 'g'],
        'gr' => [1.0, 'g'],
        'gram' => [1.0, 'g'],
        'grams' => [1.0, 'g'],
        'grammes' => [1.0, 'g'],
        'mg' => [0.001, 'g'],
        'oz' => [28.349523125, 'g'],
        'lb' => [453.59237, 'g'],
        'lbs' => [453.59237, 'g'],

        'l' => [1000.0, 'ml'],
        'lt' => [1000.0, 'ml'],
        'ltr' => [1000.0, 'ml'],
        'litre' => [1000.0, 'ml'],
        'litres' => [1000.0, 'ml'],
        'liter' => [1000.0, 'ml'],
        'liters' => [1000.0, 'ml'],
        'dl' => [100.0, 'ml'],
        'cl' => [10.0, 'ml'],
        'ml' => [1.0, 'ml'],
        'cc' => [1.0, 'ml'],
    ];

    private function __construct(
        public readonly float $value,
        public readonly MeasureUnit $unit,
    ) {}

    /** The amount in grams or millilitres, whichever this is. */
    public function inBaseUnit(): float
    {
        return $this->value;
    }

    /** "48 g", "1.5 ml" — trailing zeros trimmed. */
    public function label(): string
    {
        return rtrim(rtrim(number_format($this->value, 3, '.', ''), '0'), '.').' '.$this->unit->label();
    }

    /** A known mass. Null for a non-positive figure — an amount of nothing is not an amount. */
    public static function grams(?float $value): ?self
    {
        return $value === null ? null : self::make($value, 'g');
    }

    /** A known volume. */
    public static function millilitres(?float $value): ?self
    {
        return $value === null ? null : self::make($value, 'ml');
    }

    /**
     * Build from an already-numeric figure and a written unit — Open Food Facts'
     * `product_quantity` + `product_quantity_unit`, or our own database columns.
     * Preferred over parsing prose whenever the structured pair exists.
     *
     * A unit that is not a mass or volume yields null, which is the whole point:
     * a serving size of "1 portion" is not one gram, it is unknown.
     */
    public static function fromNumeric(int|float|string|null $value, ?string $unit): ?self
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $unit = $unit === null || trim($unit) === '' ? 'g' : strtolower(trim($unit));
        $conversion = self::CONVERSIONS[$unit] ?? null;

        if ($conversion === null) {
            return null;
        }

        return self::make(((float) $value) * $conversion[0], $conversion[1]);
    }

    /**
     * Parse a human amount string ("1 bar (48 g)", "6 x 25g", "1kg", "330ml").
     * Returns null when the string states no mass or volume at all.
     */
    public static function parse(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        // Normalise decimal commas ("1,5 l"), the multiplication sign, and case.
        $text = strtolower(trim($raw));
        $text = str_replace(['×', '✕', '⨯'], 'x', $text);
        $text = preg_replace('/(\d),(\d)/', '$1.$2', $text) ?? $text;

        // "6 x 25 g" is one hundred and fifty grams, not six of something. This
        // has to run first or the plain scan below reads the leading count.
        if (($multipack = self::parseMultipack($text)) !== null) {
            return $multipack;
        }

        // "1 bar (48 g)" — the bracketed amount is the real one, so it wins over
        // the count in front of it.
        if (preg_match_all('/\(([^)]*)\)/', $text, $groups) >= 1) {
            foreach ($groups[1] as $inner) {
                $inside = self::parseMultipack($inner) ?? self::parseFirstAmount($inner);

                if ($inside !== null) {
                    return $inside;
                }
            }
        }

        return self::parseFirstAmount($text);
    }

    /** "6 x 25g" / "2x400 g" => the product of the two. */
    private static function parseMultipack(string $text): ?self
    {
        $pattern = '/(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)\s*('.self::unitPattern().')\b/';

        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        $conversion = self::CONVERSIONS[$m[3]];

        return self::make((float) $m[1] * (float) $m[2] * $conversion[0], $conversion[1]);
    }

    /** The first "<number> <mass-or-volume unit>" in the string. */
    private static function parseFirstAmount(string $text): ?self
    {
        $pattern = '/(?<!\w)(\d+(?:\.\d+)?)\s*(?<!fl )('.self::unitPattern().')\b/';

        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        $conversion = self::CONVERSIONS[$m[2]];

        return self::make((float) $m[1] * $conversion[0], $conversion[1]);
    }

    /**
     * Units, longest first so "kg" is not matched as "g" and "litres" not as "lt".
     * Fluid ounces are deliberately absent: the UK and US measures differ by 4%,
     * Open Food Facts is global, and a silently wrong volume is worse than an
     * honest unknown — the negative lookbehind above drops "fl oz" rather than
     * reading it as a mass in ounces.
     */
    private static function unitPattern(): string
    {
        $units = array_keys(self::CONVERSIONS);

        usort($units, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return implode('|', array_map(static fn (string $u): string => preg_quote($u, '/'), $units));
    }

    private static function make(float $value, string $baseUnit): ?self
    {
        if (! is_finite($value) || $value <= 0.0 || $value > self::MAX) {
            return null;
        }

        return new self(
            round($value, 3),
            $baseUnit === 'g' ? MeasureUnit::Gram : MeasureUnit::Millilitre,
        );
    }
}
