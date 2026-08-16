<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-nutrient provenance (founder decision, Aug 2026).
 *
 * A model may now produce a nutrient figure where no source has one — but only
 * inside guardrails, and never in a way that leaves the figure mistakable for
 * one a label stated. That distinction has to survive at the level of a single
 * nutrient: a version with six stated macros and two estimated micros is not "a
 * source" or "an estimate", it is both, field by field.
 *
 * The map records only what is NOT plainly stated:
 *
 *     {"calories": "derived:energy-kj_100g", "fibre": "estimated"}
 *
 * so an ordinary label-read product stores null here and costs nothing.
 *
 * It lands on the consumption tables as well as the product one because a
 * snapshot has to carry its own provenance — six months from now, "where did
 * this number come from" must be answerable from the row that was eaten, not
 * from whatever the product happens to say by then.
 */
return new class extends Migration
{
    private const TABLES = ['product_versions', 'consumption_events', 'consumption_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->json('nutrient_origins')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('nutrient_origins');
            });
        }
    }
};
