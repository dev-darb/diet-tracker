<?php

namespace App\Nutrition;

/**
 * The one list of nutrients foody tracks (food-intelligence tranche, Aug 2026).
 *
 * Everything nutrient-shaped reads this: the value object's keys, the Open Food
 * Facts mapper, the migrations, the validator, the admin form and the display
 * layer. Adding a nutrient is one entry here plus one migration — never an edit
 * in six files, which is how the audit's D2 (energy read only as kcal) and D3
 * (salt read only as salt) survived unnoticed for so long.
 *
 * ## Storage units
 * Macros are stored the way a label states them: energy in kcal, everything else
 * in grams. Micronutrients are stored in the unit a label uses for them —
 * milligrams for the bulk minerals and vitamin C/E/B6, micrograms for the trace
 * elements and the fat-soluble vitamins — because Open Food Facts normalises
 * every weight nutriment to grams, and vitamin B12 in grams is 0.0000006.
 *
 * ## Coverage honesty
 * Fifteen micronutrients is the full European label set, chosen deliberately
 * (founder, Aug 2026). Open Food Facts states them patchily, so most products
 * will carry unknowns across most micros. That is the correct outcome: the
 * machine reports `----` for what it does not know and never fills the gap with
 * a zero.
 */
final class NutrientRegistry
{
    /** @var array<string, Nutrient>|null */
    private static ?array $nutrients = null;

    /**
     * Every tracked nutrient, keyed by column name, macros first.
     *
     * @return array<string, Nutrient>
     */
    public static function all(): array
    {
        return self::$nutrients ??= self::build();
    }

    public static function get(string $key): ?Nutrient
    {
        return self::all()[$key] ?? null;
    }

    /**
     * All nutrient keys in canonical order — the shape of a nutrition row.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The eight the app has always tracked. Their columns predate this registry
     * and their absent-means-zero construction contract is preserved.
     *
     * @return array<int, string>
     */
    public static function macroKeys(): array
    {
        return self::keysIn(NutrientGroup::Macro);
    }

    /**
     * The label micronutrients. Sparse by nature — absent always means unknown.
     *
     * @return array<int, string>
     */
    public static function microKeys(): array
    {
        return self::keysIn(NutrientGroup::Micro);
    }

    /** @return array<int, string> */
    private static function keysIn(NutrientGroup $group): array
    {
        return array_keys(array_filter(
            self::all(),
            static fn (Nutrient $n): bool => $n->group === $group,
        ));
    }

    /**
     * Eloquent casts for the nutrient columns — decimal at each nutrient's own
     * precision, so a microgram figure is not rounded away by the macros' 2dp.
     *
     * @return array<string, string>
     */
    public static function casts(): array
    {
        $casts = [];

        foreach (self::all() as $key => $nutrient) {
            $casts[$key] = 'decimal:'.$nutrient->unit->precision();
        }

        return $casts;
    }

    /**
     * Read a whole per-100g/ml nutrition row out of an Open Food Facts
     * `nutriments` payload. Unstated nutrients come back null — never 0
     * (brief §2.1).
     *
     * Two passes. The first reads the `_100g` figures. The second only runs for
     * nutrients still unknown, and only when the product states a serving size in
     * grams or millilitres: some records carry `_serving` figures without their
     * per-100 counterparts, and renormalising a stated per-serving figure is
     * arithmetic on real data, not invention. Anything still unknown after both
     * passes stays unknown.
     *
     * The returned `derived` map names the exact payload key behind every figure
     * that needed a fallback — a kilojoule reading, a sodium reading, a
     * per-serving renormalisation — so provenance can say so out loud.
     *
     * @param  array<string, mixed>  $nutriments
     * @param  float|null  $servingGrams  the serving size in g/ml, when known.
     * @return array{values: array<string, float|null>, derived: array<string, string>}
     */
    public static function readPayload(array $nutriments, ?float $servingGrams = null): array
    {
        $values = [];
        $derived = [];

        $record = static function (string $key, Nutrient $nutrient, ?array $read) use (&$values, &$derived): bool {
            if ($read === null) {
                return false;
            }

            $values[$key] = $nutrient->round($read['value']);

            if ($read['derived']) {
                $derived[$key] = $read['source'];
            }

            return true;
        };

        $servingScale = $servingGrams !== null && $servingGrams > 0.0
            ? 100.0 / $servingGrams
            : null;

        foreach (self::all() as $key => $nutrient) {
            $values[$key] = null;

            if ($record($key, $nutrient, $nutrient->readFrom($nutriments))) {
                continue;
            }

            if ($servingScale !== null) {
                $record($key, $nutrient, $nutrient->readFrom($nutriments, '_serving', $servingScale));
            }
        }

        return ['values' => $values, 'derived' => $derived];
    }

    /** 1 kilojoule in kilocalories — the thermochemical conversion, exact enough at 6 dp. */
    private const KJ_TO_KCAL = 0.239006;

    /** Salt = sodium × 2.5 (the molar ratio of NaCl to Na), the conversion every EU label uses. */
    private const SODIUM_TO_SALT = 2.5;

    /** Open Food Facts states weight nutriments per 100 g in grams. */
    private const G_TO_MG = 1000.0;

    private const G_TO_UG = 1_000_000.0;

    /**
     * @return array<string, Nutrient>
     */
    private static function build(): array
    {
        $macro = static fn (string $key, string $label, NutrientUnit $unit, array $sources, float $max): Nutrient => new Nutrient($key, $label, $unit, NutrientGroup::Macro, $sources, $max);

        $mineral = static fn (string $key, string $label, string $offKey, NutrientUnit $unit, float $max): Nutrient => new Nutrient(
            $key,
            $label,
            $unit,
            NutrientGroup::Micro,
            [$offKey => $unit === NutrientUnit::Milligram ? self::G_TO_MG : self::G_TO_UG],
            $max,
        );

        // Source names are OFF nutriment BASE names — the reader appends `_100g`
        // or `_serving`, which is what makes the per-serving fallback free.
        $nutrients = [
            // ---- Macros: the original eight -------------------------------------
            // Energy: kcal preferred, then explicit kJ, then OFF's bare `energy`
            // (which is kJ by their convention). D2 — a kJ-only record used to lose
            // its calories entirely, which then nulled the whole day's total.
            $macro('calories', 'Energy', NutrientUnit::Kcal, [
                'energy-kcal' => 1.0,
                'energy-kj' => self::KJ_TO_KCAL,
                'energy' => self::KJ_TO_KCAL,
            ], 900.0),

            $macro('protein', 'Protein', NutrientUnit::Gram, ['proteins' => 1.0], 100.0),
            $macro('carbs', 'Carbohydrate', NutrientUnit::Gram, ['carbohydrates' => 1.0], 100.0),
            $macro('sugars', 'Sugars', NutrientUnit::Gram, ['sugars' => 1.0], 100.0),
            $macro('fat', 'Fat', NutrientUnit::Gram, ['fat' => 1.0], 100.0),
            $macro('saturated_fat', 'Saturates', NutrientUnit::Gram, ['saturated-fat' => 1.0], 100.0),
            $macro('fibre', 'Fibre', NutrientUnit::Gram, ['fiber' => 1.0, 'fibre' => 1.0], 100.0),

            // D3 — salt stated only as sodium used to be lost; it is the same fact.
            $macro('salt', 'Salt', NutrientUnit::Gram, [
                'salt' => 1.0,
                'sodium' => self::SODIUM_TO_SALT,
            ], 100.0),

            // ---- Minerals --------------------------------------------------------
            $mineral('iron', 'Iron', 'iron', NutrientUnit::Milligram, 500.0),
            $mineral('calcium', 'Calcium', 'calcium', NutrientUnit::Milligram, 5_000.0),
            $mineral('potassium', 'Potassium', 'potassium', NutrientUnit::Milligram, 20_000.0),
            $mineral('magnesium', 'Magnesium', 'magnesium', NutrientUnit::Milligram, 5_000.0),
            $mineral('zinc', 'Zinc', 'zinc', NutrientUnit::Milligram, 500.0),
            $mineral('phosphorus', 'Phosphorus', 'phosphorus', NutrientUnit::Milligram, 10_000.0),
            $mineral('iodine', 'Iodine', 'iodine', NutrientUnit::Microgram, 500_000.0),
            $mineral('selenium', 'Selenium', 'selenium', NutrientUnit::Microgram, 50_000.0),

            // ---- Vitamins --------------------------------------------------------
            $mineral('vitamin_a', 'Vitamin A', 'vitamin-a', NutrientUnit::Microgram, 500_000.0),
            $mineral('vitamin_c', 'Vitamin C', 'vitamin-c', NutrientUnit::Milligram, 10_000.0),
            $mineral('vitamin_d', 'Vitamin D', 'vitamin-d', NutrientUnit::Microgram, 10_000.0),
            $mineral('vitamin_e', 'Vitamin E', 'vitamin-e', NutrientUnit::Milligram, 5_000.0),
            $mineral('vitamin_b6', 'Vitamin B6', 'vitamin-b6', NutrientUnit::Milligram, 500.0),
            $mineral('vitamin_b12', 'Vitamin B12', 'vitamin-b12', NutrientUnit::Microgram, 5_000.0),

            // Folate: OFF files it under B9, older records under `folates`.
            new Nutrient('folate', 'Folate', NutrientUnit::Microgram, NutrientGroup::Micro, [
                'vitamin-b9' => self::G_TO_UG,
                'folates' => self::G_TO_UG,
            ], 20_000.0),
        ];

        $keyed = [];

        foreach ($nutrients as $nutrient) {
            $keyed[$nutrient->key] = $nutrient;
        }

        return $keyed;
    }
}
