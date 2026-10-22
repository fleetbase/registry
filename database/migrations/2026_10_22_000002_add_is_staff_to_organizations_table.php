<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the Fleetbase staff organization: the only owner allowed for the reserved
 * `@fleetbase/*` and `fleetbase/*` package namespaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false)->after('is_personal')->index();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['is_staff']);
            $table->dropColumn('is_staff');
        });
    }
};
