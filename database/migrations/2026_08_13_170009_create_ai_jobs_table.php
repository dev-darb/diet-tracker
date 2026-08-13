<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostics for every AI call — the backbone of the model-benchmarking plan
 * and admin diagnostics (BUILD_PLAN §5, idea #4; brief §11, §14). No AI runs in
 * Milestone 1, but the table exists from day one so later milestones only have
 * to write rows. `cost` is decimal with wide scale to hold fractional-cent
 * token pricing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();

            $table->string('task_type');
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('latency_ms');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost', 10, 6)->nullable();
            $table->string('status');
            $table->unsignedInteger('retries')->default(0);
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('result_status')->nullable();

            $table->timestamps();

            $table->index(['task_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
