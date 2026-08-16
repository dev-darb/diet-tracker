<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The resident chef's durable artifact (tranche 4, Aug 2026): one suggestion
 * per user per day per meal slot, generated in the background against real
 * stock and rendered instantly by the Pantry — the interface never waits for
 * the AI. `consumption_event_id` closes the loop when the user cooks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chef_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('suggested_on');
            $table->string('slot', 16); // breakfast | lunch | dinner | snack

            $table->string('title', 120);
            $table->text('summary')->nullable();
            $table->json('ingredients');            // [{name, amount, pantry_item_id|null}]
            $table->json('upgrades');
            $table->json('steps');
            $table->decimal('approx_calories', 7, 1)->nullable(); // display-only estimates (~)
            $table->decimal('approx_protein', 6, 1)->nullable();

            $table->foreignId('consumption_event_id')->nullable()
                ->constrained()->nullOnDelete();    // set when the user cooked it

            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'suggested_on', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chef_suggestions');
    }
};
