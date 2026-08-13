<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for a product version — where each nutrition figure came from and
 * how sure we are (BUILD_PLAN §5; brief §2.2, §7.13). Confidence is a decimal
 * band in [0,1].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_version_id')->constrained()->cascadeOnDelete();

            $table->string('source_url')->nullable();
            $table->string('source_type');
            $table->timestamp('retrieved_at');
            $table->decimal('confidence', 4, 3);
            $table->text('evidence_summary')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sources');
    }
};
