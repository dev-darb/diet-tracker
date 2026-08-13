<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A logged eating event — a single product or a meal (BUILD_PLAN §5; brief
 * §8.1/§8.4). Total macro columns are deterministic sums produced by
 * NutritionCalculator (never the LLM, §8.9). Consumption itself is Milestone 4;
 * this table exists now so the pantry ledger's `linked_consumption_event_id`
 * FK is ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumption_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->string('name')->nullable();
            $table->timestamp('consumed_at');

            $table->decimal('calories', 8, 2);
            $table->decimal('protein', 8, 2);
            $table->decimal('carbs', 8, 2);
            $table->decimal('sugars', 8, 2);
            $table->decimal('fat', 8, 2);
            $table->decimal('saturated_fat', 8, 2);
            $table->decimal('fibre', 8, 2);
            $table->decimal('salt', 8, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumption_events');
    }
};
