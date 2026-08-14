<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Natural-portion consumption (Phase 1). When a consumption is logged from a
 * portion chip ("one (48 g)", "half the pack (200 g)") the human wording is
 * snapshotted here so history can echo the user's language back. Nullable:
 * custom amounts and pre-feature rows have no label, and the numeric
 * quantity/unit on the row remains the source of truth for all maths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumption_items', function (Blueprint $table) {
            $table->string('portion_label', 60)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('consumption_items', function (Blueprint $table) {
            $table->dropColumn('portion_label');
        });
    }
};
