<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an eating-out meal was eaten ("Wagamama", "the cafe by work"). Feeds
 * estimation (published chain menus beat typical composition) and the
 * cross-context insights the reframe calls for (BUILD_PLAN §1b). Nullable —
 * pantry/home-cooked events have no venue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumption_events', function (Blueprint $table) {
            $table->string('venue', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('consumption_events', function (Blueprint $table) {
            $table->dropColumn('venue');
        });
    }
};
