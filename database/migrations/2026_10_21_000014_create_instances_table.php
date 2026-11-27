<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instances', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->uuid('organization_uuid')->index();
            $table->uuid('registered_by_account_uuid')->nullable()->index();
            $table->string('name');
            $table->string('host')->nullable()->index();
            $table->string('kind', 16)->default('self_hosted')->index();
            $table->string('console_version', 64)->nullable();
            $table->string('api_version', 64)->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instances');
    }
};
