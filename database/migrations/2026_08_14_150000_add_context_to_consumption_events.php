<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger-is-the-product reframe (BUILD_PLAN "Reframe, Aug 2026"): every
 * meal enters the same consumption ledger regardless of where it came from,
 * tagged with its source context and data fidelity.
 *
 * - `context`    pantry | home_cooked | eating_out — how the food was sourced.
 *                Pre-reframe rows were all pantry consumes, hence the default.
 * - `estimated`  true when the figures are typical-composition estimates
 *                (eating out) rather than label-derived data. Estimates are
 *                always shown as estimates (brief §2.1 — never fake precision).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumption_events', function (Blueprint $table) {
            $table->string('context', 20)->default('pantry')->after('type');
            $table->boolean('estimated')->default(false)->after('context');
        });
    }

    public function down(): void
    {
        Schema::table('consumption_events', function (Blueprint $table) {
            $table->dropColumn(['context', 'estimated']);
        });
    }
};
