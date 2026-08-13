<?php

use App\ValueObjects\NutrientValues;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 4 data-model fix: the snapshotted macro columns on
 * `consumption_events` and `consumption_items` become NULLABLE (BUILD_PLAN §6
 * J4.1, idea #6; brief §2.1/§8.9).
 *
 * A consumption snapshot copies the product version's figures at the moment of
 * eating. Those figures can legitimately be UNKNOWN — Open Food Facts and label
 * OCR routinely omit fibre/salt, and M2 already relaxed product_versions to
 * nullable for exactly this reason. Snapshotting 0 for "not stated" would be a
 * false nutritional claim, so an unknown nutrient is persisted as NULL and
 * propagates as unknown through {@see NutrientValues}. Never
 * fabricated as 0.
 */
return new class extends Migration
{
    /** The eight macro columns that may legitimately be unknown. */
    private const NUTRIENT_COLUMNS = [
        'calories', 'protein', 'carbs', 'sugars',
        'fat', 'saturated_fat', 'fibre', 'salt',
    ];

    private const TABLES = ['consumption_events', 'consumption_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                foreach (self::NUTRIENT_COLUMNS as $column) {
                    $blueprint->decimal($column, 8, 2)->nullable()->change();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            // Default any unknown (null) values to 0 first so the NOT NULL
            // change cannot fail on existing rows.
            foreach (self::NUTRIENT_COLUMNS as $column) {
                DB::table($table)->whereNull($column)->update([$column => 0]);
            }

            Schema::table($table, function (Blueprint $blueprint) {
                foreach (self::NUTRIENT_COLUMNS as $column) {
                    $blueprint->decimal($column, 8, 2)->nullable(false)->change();
                }
            });
        }
    }
};
