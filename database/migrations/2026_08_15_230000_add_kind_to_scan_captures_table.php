<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified capture (Aug 2026): the scanner is the single camera entrypoint, so
 * a capture now records WHAT the triage decided it is (kind), the honest
 * pipeline stage for progress feedback, and — for prepared meals — the dish
 * name plus the interpreter's full reading, persisted at capture time because
 * serverless local disk cannot promise the photo is still readable when the
 * user opens the meal flow later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_captures', function (Blueprint $table) {
            $table->string('kind', 24)->nullable()->after('barcode');
            $table->string('stage', 16)->nullable()->after('status');
            $table->string('dish_name')->nullable()->after('stage');
            $table->json('meal_reading')->nullable()->after('dish_name');
        });
    }

    public function down(): void
    {
        Schema::table('scan_captures', function (Blueprint $table) {
            $table->dropColumn(['kind', 'stage', 'dish_name', 'meal_reading']);
        });
    }
};
