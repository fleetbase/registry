<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `max_activations` null means unlimited seats (automatic free licences).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->unsignedInteger('max_activations')->nullable()->default(5)->change();
        });
    }

    public function down(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->unsignedInteger('max_activations')->nullable(false)->default(5)->change();
        });
    }
};
