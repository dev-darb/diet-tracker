<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dated set of nutrition figures for a canonical product (BUILD_PLAN §5;
 * brief §7.12, §10.3 versioning). The original `serving_basis` is preserved;
 * NutritionCalculator derives the other basis on demand.
 *
 * Nutrient columns are DECIMAL (not float) to avoid rounding drift, and the
 * models cast them to `decimal:2`. `status` is indexed for the admin review
 * queue and resolution flows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_product_id')->constrained()->cascadeOnDelete();

            $table->string('serving_basis');
            $table->decimal('serving_size_value', 10, 3)->nullable();
            $table->string('serving_size_unit')->nullable();

            // Macros — stored per the row's serving_basis. Decimal, never float.
            $table->decimal('calories', 8, 2);
            $table->decimal('protein', 8, 2);
            $table->decimal('carbs', 8, 2);
            $table->decimal('sugars', 8, 2);
            $table->decimal('fat', 8, 2);
            $table->decimal('saturated_fat', 8, 2);
            $table->decimal('fibre', 8, 2);
            $table->decimal('salt', 8, 2);

            $table->text('ingredients')->nullable();
            $table->json('allergens')->nullable();

            $table->timestamp('effective_from');
            $table->timestamp('verified_at')->nullable();
            $table->string('status')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_versions');
    }
};
