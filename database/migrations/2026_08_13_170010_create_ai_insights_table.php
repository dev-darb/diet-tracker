<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generated, human-readable nutrition insight for a period (BUILD_PLAN §5;
 * brief §9.6, §12). `structured_inputs` records the deterministic analytics the
 * insight was built from, so an insight is always explainable. Insights ship in
 * Milestone 7; the table exists now for the shared data model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('insight_type');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('title');
            $table->text('body');
            $table->json('structured_inputs');
            $table->string('provider');
            $table->string('model');

            $table->timestamps();

            $table->index(['user_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
