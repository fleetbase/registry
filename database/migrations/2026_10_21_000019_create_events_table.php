<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('subject_type')->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->string('actor_type')->nullable();
            $table->uuid('actor_uuid')->nullable();
            $table->string('kind')->index();
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['subject_type', 'subject_uuid']);
            $table->index(['actor_type', 'actor_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
