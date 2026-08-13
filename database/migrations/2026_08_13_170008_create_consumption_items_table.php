<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One component of a consumption event (BUILD_PLAN §5, idea #6; brief §8.4).
 *
 * The macro columns are SNAPSHOTTED at the moment of eating so historical days
 * never change when a product is later reformulated (§10.3). The product/version
 * FKs are nullable and null-out on delete so history survives a product purge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumption_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumption_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_product_id')->nullable()
                ->constrained('canonical_products')->nullOnDelete();
            $table->foreignId('product_version_id')->nullable()
                ->constrained('product_versions')->nullOnDelete();

            $table->decimal('quantity', 12, 3);
            $table->string('unit');

            // Snapshotted macros for exactly this quantity.
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
        Schema::dropIfExists('consumption_items');
    }
};
