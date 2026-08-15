<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scan capture is the durable artifact behind the pipelined scanner ("the
 * interface should never make the user wait for the AI", founder, Aug 2026):
 * every shutter press / barcode read becomes a row immediately, a queued job
 * identifies + resolves it in the background, and the scanner's results stack
 * polls these rows. Distinct from product_resolution_jobs (the resolution
 * audit trail): a capture is user-facing work-in-flight, and it survives
 * refreshes and interruptions — completed background work is never lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What was captured: a stored photo, an on-device barcode, or both.
            $table->string('image_path')->nullable();
            $table->string('barcode', 64)->nullable();

            // "…and I'm eating it now": log to today's intake on top of stocking.
            $table->boolean('eat_now')->default(false);

            $table->string('status')->default('queued');
            $table->string('provenance')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();

            $table->foreignId('matched_product_id')->nullable()
                ->constrained('canonical_products')->nullOnDelete();
            $table->foreignId('pantry_item_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('consumption_event_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('resolution_job_id')->nullable()
                ->constrained('product_resolution_jobs')->nullOnDelete();

            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_captures');
    }
};
