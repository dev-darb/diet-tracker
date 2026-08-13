<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scan-flow columns for the resolution audit trail (BUILD_PLAN J2.5; brief §7.2,
 * §7.6, §12).
 *
 * - `uploaded_image_path` — the captured photo stored on the local `public` disk
 *   (image-retention policy is deferred to M8; we just keep the path now).
 * - `user_correction` / `corrected_at` — when a user taps "Wrong product" on the
 *   confirmation screen the rejection is recorded as evidence, because
 *   corrections are valuable product intelligence (brief §7.6, §11 User
 *   Corrections). Kept as JSON so the rejected product + detected fields travel
 *   together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_resolution_jobs', function (Blueprint $table) {
            $table->string('uploaded_image_path')->nullable()->after('user_id');
            $table->json('user_correction')->nullable()->after('latency_ms');
            $table->timestamp('corrected_at')->nullable()->after('user_correction');
        });
    }

    public function down(): void
    {
        Schema::table('product_resolution_jobs', function (Blueprint $table) {
            $table->dropColumn(['uploaded_image_path', 'user_correction', 'corrected_at']);
        });
    }
};
