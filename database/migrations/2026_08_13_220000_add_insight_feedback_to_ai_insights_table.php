<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 7 additions to `ai_insights` (BUILD_PLAN §6 J7.2; brief §9.6).
 *
 *  - `priority` / `focus_key` — the deterministic focus behind the phrasing, so
 *    the card can style/route by it;
 *  - `pantry_item_ids` — the pantry items the insight references, powering the
 *    "Show me what I could eat" action deterministically (never LLM-invented);
 *  - `dismissed_at` — feedback that hides an insight for its period so it isn't
 *    shown again (a dismissed insight is not regenerated for the same week).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->string('priority')->nullable()->after('body');
            $table->string('focus_key')->nullable()->after('priority');
            $table->json('pantry_item_ids')->nullable()->after('structured_inputs');
            $table->timestamp('dismissed_at')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->dropColumn(['priority', 'focus_key', 'pantry_item_ids', 'dismissed_at']);
        });
    }
};
