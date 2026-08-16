<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clean names alongside the raw one (spec §4, stage 2).
 *
 * Open Food Facts names are typed off wrappers by whoever got there first:
 * "TESCO FINEST CHICKEN TIKKA MASALA 400G". Rendering that is what makes an app
 * look like somebody's spreadsheet, and it buries the actual food inside
 * retailer branding and a pack size the row already shows beside it.
 *
 * - `raw_name`     exactly what the source said, kept untouched forever. The
 *                  moment we cannot reproduce what a source gave us, we have
 *                  lost the ability to tell our mistakes from theirs.
 * - `display_name` what a person reads: "Tesco Finest Chicken Tikka Masala".
 *
 * `name` keeps its job and gets better at it: it has always held the food's
 * name, and now holds the CANONICAL one — "Chicken Tikka Masala", the thing a
 * search should match and a recipe would call it. Existing rows keep working
 * untouched; the backfill cleans them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canonical_products', function (Blueprint $table) {
            $table->string('raw_name')->nullable()->after('name');
            $table->string('display_name')->nullable()->after('raw_name');
        });
    }

    public function down(): void
    {
        Schema::table('canonical_products', function (Blueprint $table) {
            $table->dropColumn(['raw_name', 'display_name']);
        });
    }
};
