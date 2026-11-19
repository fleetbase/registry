<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installs', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('instance_uuid')->index();
            $table->uuid('extension_uuid')->index();
            $table->uuid('release_uuid')->nullable()->index();
            $table->string('status', 32)->default('installing')->index();
            $table->string('installed_by')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->index(['extension_uuid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installs');
    }
};
