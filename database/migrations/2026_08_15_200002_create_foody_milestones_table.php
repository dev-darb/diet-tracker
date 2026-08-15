<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal milestones (spec §18): bests, 90+ weeks, plant-diversity marks,
 * streaks — structured so later private circles can share achievements
 * without replacing the score model. No public leaderboards; a milestone is
 * always personal ("your best"), never comparative ("better than").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foody_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);        // personal_best | first_90 | strong_week | plant_30 | log_streak_7 ...
            $table->date('achieved_on');
            $table->json('payload');           // structured share data (score, value, label, band...)
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'achieved_on']);
            $table->index(['user_id', 'achieved_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foody_milestones');
    }
};
