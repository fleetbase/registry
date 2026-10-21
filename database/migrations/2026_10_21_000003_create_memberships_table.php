<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('organization_uuid')->index();
            $table->uuid('account_uuid')->index();
            $table->string('role')->default('member');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['organization_uuid', 'account_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
