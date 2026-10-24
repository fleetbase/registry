<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `description` and `promotional_text` (the website's rich overview, sanitised HTML) accept
 * 20,000 characters, which can exceed MySQL's 64 KB `text` in multibyte UTF-8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->mediumText('description')->nullable()->change();
            $table->mediumText('promotional_text')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
            $table->text('promotional_text')->nullable()->change();
        });
    }
};
