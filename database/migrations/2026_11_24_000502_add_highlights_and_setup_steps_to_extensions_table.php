<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two short lists on a listing: `highlights` ("What you'll get") and `setup_steps` ("After you
 * install"), up to five lines each, set in Studio or extension.json's `listing` block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->json('highlights')->nullable()->after('languages');
            $table->json('setup_steps')->nullable()->after('highlights');
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropColumn(['highlights', 'setup_steps']);
        });
    }
};
