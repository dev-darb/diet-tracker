<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable pantry ledger — the source of truth for stock (BUILD_PLAN §5,
 * idea #5; brief §7.10/§8.7). Every row is a signed `quantity_delta`; the sum
 * of an item's deltas always equals its cached `pantry_items.current_quantity`
 * (proven by the reconciliation test).
 *
 * `linked_consumption_event_id` is wired now but only populated in Milestone 4
 * (consumption); it nulls-out on delete so the ledger is never orphaned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pantry_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pantry_item_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->decimal('quantity_delta', 12, 3);
            $table->string('unit');
            $table->foreignId('linked_consumption_event_id')->nullable()
                ->constrained('consumption_events')->nullOnDelete();
            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index(['pantry_item_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pantry_transactions');
    }
};
