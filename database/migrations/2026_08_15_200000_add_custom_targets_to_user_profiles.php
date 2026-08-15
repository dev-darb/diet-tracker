<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual calorie/macro targets (Foody Score spec §4): explicit user targets
 * become the scoring targets, overriding derived defaults. Null = "use the
 * derived target" — never zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('custom_calorie_target')->nullable()->after('dietary_pattern');
            $table->decimal('custom_protein_g', 6, 1)->nullable()->after('custom_calorie_target');
            $table->decimal('custom_carbs_g', 6, 1)->nullable()->after('custom_protein_g');
            $table->decimal('custom_fat_g', 6, 1)->nullable()->after('custom_carbs_g');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['custom_calorie_target', 'custom_protein_g', 'custom_carbs_g', 'custom_fat_g']);
        });
    }
};
