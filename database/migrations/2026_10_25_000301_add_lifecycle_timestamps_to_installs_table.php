<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publisher analytics read installs, updates and uninstalls from `installs`, so each row keeps
 * when it was installed and how it ended: `uninstalled_at` (the instance removed the extension)
 * or `replaced_at` (a newer install on the same instance took over: an update). A row that
 * replaced another carries the release it replaced in `previous_release_uuid`.
 *
 * Indexes serve the stats queries: date ranges per extension on each lifecycle column, the
 * per-version split of active installs (replacing the narrower extension and status index), and
 * purchases per extension by date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installs', function (Blueprint $table) {
            $table->timestamp('installed_at')->nullable()->after('finished_at');
            $table->timestamp('uninstalled_at')->nullable()->after('installed_at');
            $table->timestamp('replaced_at')->nullable()->after('uninstalled_at');
            $table->uuid('previous_release_uuid')->nullable()->after('release_uuid');

            // Each carries the column its query also reads, so the series scans only the index.
            $table->index(['extension_uuid', 'installed_at', 'previous_release_uuid']);
            $table->index(['extension_uuid', 'uninstalled_at', 'installed_at']);
            // Covers what (extension_uuid, status) served, plus the per-version split.
            $table->index(['extension_uuid', 'status', 'release_uuid']);
            $table->dropIndex(['extension_uuid', 'status']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['extension_uuid', 'created_at']);
        });

        // Rows written before these columns: installed when the attempt finished (an installed
        // row) or began (an ended one); an ended row counts as uninstalled when it ended. Updates
        // made before this point read as an uninstall and an install.
        DB::table('installs')->where('status', 'installed')->update(['installed_at' => DB::raw('COALESCE(finished_at, started_at, created_at)')]);
        DB::table('installs')->where('status', 'uninstalled')->update([
            'installed_at' => DB::raw('COALESCE(started_at, created_at)'),
            'uninstalled_at' => DB::raw('COALESCE(finished_at, updated_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['extension_uuid', 'created_at']);
        });

        Schema::table('installs', function (Blueprint $table) {
            $table->dropIndex(['extension_uuid', 'installed_at', 'previous_release_uuid']);
            $table->dropIndex(['extension_uuid', 'uninstalled_at', 'installed_at']);
            $table->index(['extension_uuid', 'status']);
            $table->dropIndex(['extension_uuid', 'status', 'release_uuid']);
            $table->dropColumn(['installed_at', 'uninstalled_at', 'replaced_at', 'previous_release_uuid']);
        });
    }
};
