<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data-quality columns on a nutrition version (spec §3, food-intelligence
 * tranche Aug 2026).
 *
 * - `nutrient_coverage` — how much of the tracked nutrition this version
 *   actually states, 0..1. Feeds Foody Score's nutrient-coverage confidence and
 *   lets the app say "we know most of this" without pretending to know all of it.
 * - `sanity_findings`   — what the deterministic checks noticed: sugars above
 *   carbohydrate, energy that does not follow from the macros, a serving larger
 *   than its pack. Null means "checked, nothing amiss"; the findings are
 *   recorded, never acted on — a corrected figure would be a fabricated one.
 *
 * A version with findings keeps its data and drops to `needs_review`, so it is
 * visible in the admin queue while still being usable. Blanking a product's
 * nutrition because one cross-check disagreed would lose real data over a
 * suspicion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->decimal('nutrient_coverage', 4, 3)->nullable()->after('salt');
            $table->json('sanity_findings')->nullable()->after('nutrient_coverage');
        });
    }

    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropColumn(['nutrient_coverage', 'sanity_findings']);
        });
    }
};
