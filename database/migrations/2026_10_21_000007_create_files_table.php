<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->uuid('uploader_account_uuid')->nullable()->index();
            $table->string('subject_type')->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->string('type')->nullable()->index();
            $table->string('disk')->default('s3');
            $table->string('path');
            $table->string('name')->nullable();
            $table->string('original_filename')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime')->nullable();
            $table->string('checksum', 128)->nullable()->index();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->index(['subject_type', 'subject_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
