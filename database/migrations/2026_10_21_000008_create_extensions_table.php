<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extensions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->uuid('organization_uuid')->index();
            $table->uuid('category_uuid')->nullable()->index();
            $table->uuid('icon_file_uuid')->nullable()->index();
            $table->uuid('created_by_account_uuid')->nullable()->index();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->text('promotional_text')->nullable();
            $table->json('tags')->nullable();
            $table->json('languages')->nullable();
            $table->string('hosting')->default('both')->index();
            $table->string('status')->default('draft')->index();
            $table->string('website_url')->nullable();
            $table->string('repo_url')->nullable();
            $table->string('support_url')->nullable();
            $table->string('privacy_url')->nullable();
            $table->string('tos_url')->nullable();
            $table->string('copyright')->nullable();
            $table->string('fa_icon')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable()->index();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('extensions', function (Blueprint $table) {
                $table->fullText(['name', 'subtitle', 'description'], 'extensions_search_fulltext');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('extensions');
    }
};
