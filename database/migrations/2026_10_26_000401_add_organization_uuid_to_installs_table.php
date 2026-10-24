<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The organization an install is for. A self-hosted instance installs for its own organization;
 * the cloud instance serves every cloud company, so each company's installs of an extension are
 * their own rows (and seats) on that one instance. Existing rows take their instance's
 * organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installs', function (Blueprint $table) {
            $table->uuid('organization_uuid')->nullable()->after('instance_uuid');
            // Named: the default name is longer than MySQL's 64 characters.
            $table->index(['instance_uuid', 'organization_uuid', 'extension_uuid', 'status'], 'installs_instance_organization_extension_status_index');
        });

        DB::table('installs')->whereNull('organization_uuid')->update([
            'organization_uuid' => DB::table('instances')->select('organization_uuid')->whereColumn('instances.uuid', 'installs.instance_uuid')->limit(1),
        ]);
    }

    public function down(): void
    {
        Schema::table('installs', function (Blueprint $table) {
            $table->dropIndex('installs_instance_organization_extension_status_index');
            $table->dropColumn('organization_uuid');
        });
    }
};
