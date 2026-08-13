<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for one scan/resolution attempt (BUILD_PLAN §5; brief §12).
 *
 * `user_id` (who scanned) and `matched_product_id` (what we resolved to, if
 * anything) are nullable FKs that null-out on delete so history survives. The
 * detected fields from identification are kept as JSON. AI is not used in
 * Milestone 1 — the model_* / latency columns are populated by Milestone 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_resolution_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->json('detected_fields')->nullable();
            $table->decimal('detection_confidence', 4, 3)->nullable();
            $table->foreignId('matched_product_id')->nullable()
                ->constrained('canonical_products')->nullOnDelete();
            $table->string('status');
            $table->string('model_provider')->nullable();
            $table->string('model_name')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_resolution_jobs');
    }
};
