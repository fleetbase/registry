<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private extensions and trusted publishing.
 *
 * - `extensions.visibility`: `public` (the catalog) or `private` (only the owner and organizations
 *   holding a grant, a paid licence or, for public extensions, any licence see or download it).
 * - `licences.instance_uuids`: a `grant` licence may be limited to some of the organization's
 *   instances; null means every instance (and personal tokens) of the organization.
 * - `trusted_publishers`: GitHub Actions workflows allowed to exchange an OIDC token for a
 *   short-lived publish token (`flbt_`) limited to one extension; the token row records which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->string('visibility', 16)->default('public')->after('status')->index();
        });

        Schema::table('licences', function (Blueprint $table) {
            $table->json('instance_uuids')->nullable()->after('max_activations');
        });

        Schema::create('trusted_publishers', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->uuid('extension_uuid')->index();
            $table->uuid('created_by_account_uuid')->index();
            $table->string('provider', 32)->default('github');
            $table->string('repository', 200);
            $table->string('workflow', 200);
            $table->string('environment', 200)->nullable();
            $table->json('refs');
            // Pinned on first use so a deleted and re-created repository or owner cannot reuse it.
            $table->string('repository_id', 64)->nullable();
            $table->string('repository_owner_id', 64)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['provider', 'repository']);
        });

        Schema::table('tokens', function (Blueprint $table) {
            $table->uuid('extension_uuid')->nullable()->after('organization_uuid')->index();
            $table->uuid('trusted_publisher_uuid')->nullable()->after('extension_uuid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropIndex(['extension_uuid']);
            $table->dropIndex(['trusted_publisher_uuid']);
            $table->dropColumn(['extension_uuid', 'trusted_publisher_uuid']);
        });

        Schema::dropIfExists('trusted_publishers');

        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn('instance_uuids');
        });

        Schema::table('extensions', function (Blueprint $table) {
            $table->dropIndex(['visibility']);
            $table->dropColumn('visibility');
        });
    }
};
