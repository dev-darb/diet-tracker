<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foody Score records (spec §15, §19). One row per user per day; the live day
 * updates as evidence lands, and past days are frozen with the algorithm
 * version that produced them — never silently recalculated. Enough structured
 * reasoning is persisted for every score to explain itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foody_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('score_date');

            $table->unsignedTinyInteger('score');           // 0–100, displayed (stability-smoothed)
            $table->unsignedTinyInteger('raw_score');       // 0–100, pre-smoothing engine output
            $table->string('band', 20);                     // internal semantic band key
            $table->string('display_state', 20);            // firm | provisional | building

            $table->json('pillars');                        // per-pillar score/weight/confidence/components
            $table->json('reason_codes');
            $table->json('contributors');                   // ['up' => [...], 'down' => [...], 'largest_delta' => ...]
            $table->json('confidence');                     // day_completeness / nutrient_coverage / historical

            $table->string('algorithm_version', 40);
            $table->string('target_rules_version', 40);

            $table->timestamps();

            $table->unique(['user_id', 'score_date']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foody_scores');
    }
};
