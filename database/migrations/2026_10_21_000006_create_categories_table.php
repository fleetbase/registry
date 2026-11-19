<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('internal_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedInteger('sort')->default(0)->index();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
