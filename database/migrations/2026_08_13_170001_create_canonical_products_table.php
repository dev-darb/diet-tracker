<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical (shared) product identity (BUILD_PLAN §5; brief §7.1).
 *
 * `gtin` (barcode) is unique WHEN PRESENT: it is nullable, and a plain nullable
 * unique index is correct here because Postgres (and sqlite) treat multiple
 * NULLs as distinct — many products may have no barcode without colliding
 * (§21 Q12). A `(brand, name)` index backs fuzzy lookup before any LLM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_products', function (Blueprint $table) {
            $table->id();

            $table->string('gtin')->nullable()->unique();
            $table->string('brand');
            $table->string('name');
            $table->string('variant')->nullable();
            $table->decimal('pack_size_value', 10, 3)->nullable();
            $table->string('pack_size_unit')->nullable();
            $table->string('category')->nullable();
            $table->string('primary_image_path')->nullable();

            $table->timestamps();

            $table->index(['brand', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_products');
    }
};
