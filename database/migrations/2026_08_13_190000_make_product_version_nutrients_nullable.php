<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 2 data-model fix: nutrient columns on product_versions become
 * NULLABLE (BUILD_PLAN §6 J2.3; brief §2.1). Open Food Facts frequently omits
 * fields (fibre and salt especially). Storing 0 for "not stated" is a false
 * nutritional claim, so an unknown value must be persisted as NULL and treated
 * as unknown everywhere — never fabricated as 0. NutritionCalculator /
 * NutrientValues propagate that null through their arithmetic.
 */
return new class extends Migration
{
    /** The eight macro columns that may legitimately be unknown. */
    private const NUTRIENT_COLUMNS = [
        'calories', 'protein', 'carbs', 'sugars',
        'fat', 'saturated_fat', 'fibre', 'salt',
    ];

    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            foreach (self::NUTRIENT_COLUMNS as $column) {
                $table->decimal($column, 8, 2)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Reinstate NOT NULL, defaulting any unknown (null) values to 0 first so
        // the column change cannot fail on existing rows.
        foreach (self::NUTRIENT_COLUMNS as $column) {
            DB::table('product_versions')
                ->whereNull($column)
                ->update([$column => 0]);
        }

        Schema::table('product_versions', function (Blueprint $table) {
            foreach (self::NUTRIENT_COLUMNS as $column) {
                $table->decimal($column, 8, 2)->nullable(false)->change();
            }
        });
    }
};
