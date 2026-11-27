<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licences', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->uuid('extension_uuid')->index();
            $table->uuid('organization_uuid')->index();
            $table->uuid('purchase_uuid')->nullable()->index();
            $table->string('kind', 16)->default('free')->index();
            $table->unsignedInteger('max_activations')->default(5);
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->index(['extension_uuid', 'organization_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licences');
    }
};
