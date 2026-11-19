<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->uuid('extension_uuid')->index();
            $table->uuid('created_by_account_uuid')->nullable()->index();
            $table->string('version', 64);
            $table->string('channel', 16)->default('stable')->index();
            $table->string('status', 32)->default('uploaded')->index();
            $table->json('manifest')->nullable();
            $table->json('compat')->nullable();
            $table->uuid('engine_tarball_file_uuid')->nullable();
            $table->uuid('api_tarball_file_uuid')->nullable();
            $table->uuid('engine_dist_file_uuid')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 128)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('yanked_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['extension_uuid', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
