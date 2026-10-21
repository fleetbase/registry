<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_checks', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('release_uuid')->index();
            $table->string('check', 32)->index();
            $table->boolean('passed')->default(false);
            $table->json('output')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['release_uuid', 'check']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_checks');
    }
};
