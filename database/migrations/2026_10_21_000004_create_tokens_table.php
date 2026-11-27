<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->uuid('account_uuid')->index();
            $table->uuid('organization_uuid')->nullable()->index();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 16)->index();
            $table->string('last_four', 4)->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};
