<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff feature a published listing (`featured_at`, set from the review console). The catalog
 * filters on it (`featured=1`) and sorts featured listings first (`sort=featured`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->timestamp('featured_at')->nullable()->after('published_at');
            $table->index('featured_at');
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropIndex(['featured_at']);
            $table->dropColumn('featured_at');
        });
    }
};
