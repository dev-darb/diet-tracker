<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One profile row per user (brief §6.5, §12; BUILD_PLAN §5, idea #7).
 *
 * Deliberately denormalised: typed columns for the scalar bits and JSON for the
 * list-y bits (preferences, allergies, avoided foods). Everything is nullable
 * except primary_goal — onboarding must never feel like a medical form (§6.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('primary_goal');

            $table->date('date_of_birth')->nullable();
            $table->string('sex')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('activity_level')->nullable();
            $table->string('dietary_pattern')->nullable();

            $table->json('dietary_preferences')->nullable();
            $table->json('allergies')->nullable();
            $table->json('avoided_foods')->nullable();

            $table->timestamp('onboarding_completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
