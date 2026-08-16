<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The European label micronutrient set (food-intelligence tranche, Aug 2026;
 * founder decision: capture and surface now).
 *
 * Fifteen vitamins and minerals join the eight macros on all three nutrition
 * tables — the product's stated figures, and the snapshots taken at the moment
 * of eating, which is where Foody Score reads a day from.
 *
 * ## Nullable, always
 * Open Food Facts states these patchily. Every column is nullable and an
 * unstated nutrient is persisted as NULL, exactly as the macros already are
 * (see the 2026_08_13_19/21 migrations). A zero here would be a false claim that
 * the food contains none of the nutrient.
 *
 * ## Units and precision
 * Values are stored in the unit a label quotes them in — milligrams for the bulk
 * minerals and vitamins C, E and B6; micrograms for the trace elements and the
 * fat-soluble vitamins. Open Food Facts normalises everything to grams, where
 * vitamin B12 arrives as 0.0000006 and would round to nothing; App\Nutrition
 * \NutrientRegistry owns that conversion. decimal(10,3) then holds both a
 * 20,000 mg/100g potassium figure and a 0.001 mg trace without float drift.
 *
 * The column list is written out rather than read from the registry on purpose:
 * a migration is a historical record of what the schema became on this date, and
 * must not change meaning when the registry gains a nutrient later.
 */
return new class extends Migration
{
    /** @var array<string, string> column => canonical unit (documentation; all columns are identical) */
    private const MICRONUTRIENTS = [
        'iron' => 'mg',
        'calcium' => 'mg',
        'potassium' => 'mg',
        'magnesium' => 'mg',
        'zinc' => 'mg',
        'phosphorus' => 'mg',
        'iodine' => 'ug',
        'selenium' => 'ug',
        'vitamin_a' => 'ug',
        'vitamin_c' => 'mg',
        'vitamin_d' => 'ug',
        'vitamin_e' => 'mg',
        'vitamin_b6' => 'mg',
        'vitamin_b12' => 'ug',
        'folate' => 'ug',
    ];

    /**
     * product_versions holds a product's stated figures; the consumption tables
     * hold the snapshot taken when the food was eaten, which must never change
     * afterwards (brief §10.3).
     */
    private const TABLES = ['product_versions', 'consumption_events', 'consumption_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                foreach (array_keys(self::MICRONUTRIENTS) as $column) {
                    $blueprint->decimal($column, 10, 3)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(array_keys(self::MICRONUTRIENTS));
            });
        }
    }
};
