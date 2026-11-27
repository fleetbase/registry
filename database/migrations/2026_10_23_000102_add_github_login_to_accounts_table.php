<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with GitHub: one registry account per GitHub account (`github_id` becomes unique) and
 * the GitHub login shown on the profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['github_id']);
            $table->unique('github_id');
            $table->string('github_login')->nullable()->after('github_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('github_login');
            $table->dropUnique(['github_id']);
            $table->index('github_id');
        });
    }
};
