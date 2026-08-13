<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's current stock of one canonical product (BUILD_PLAN §5, idea #5;
 * brief §7.7). `current_quantity` is a CACHED balance — the pantry_transactions
 * ledger is the source of truth, and PantryService keeps this column in sync
 * inside the same DB transaction. Stored as decimal(12,3) to hold fractional
 * grams/ml/packs without float drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pantry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_product_id')->constrained()->cascadeOnDelete();

            $table->decimal('current_quantity', 12, 3)->default(0);
            $table->string('quantity_unit');
            $table->timestamp('purchased_at')->nullable();
            $table->date('expiry_date')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'canonical_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pantry_items');
    }
};
