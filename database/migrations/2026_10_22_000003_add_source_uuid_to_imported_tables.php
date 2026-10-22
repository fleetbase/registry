<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `source_uuid` holds the uuid of the cloud registry-bridge row a record was imported
 * from, so `registry:import` can re-run idempotently. Null for records created here.
 */
return new class extends Migration
{
    public const TABLES = [
        'accounts', 'organizations', 'memberships', 'categories', 'files', 'extensions',
        'extension_packages', 'prices', 'releases', 'purchases', 'licences', 'instances',
        'installs', 'licence_activations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('source_uuid', 191)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_source_uuid_index');
                $table->dropColumn('source_uuid');
            });
        }
    }
};
