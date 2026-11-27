<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package clients verify downloads with different digests: npm reads `dist.integrity`
 * (SRI sha512) and `dist.shasum` (sha1), Composer reads `dist.shasum` (sha1). The
 * existing `checksum` column keeps the sha256 hex used internally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->char('shasum', 40)->nullable()->after('checksum');
            $table->string('integrity', 128)->nullable()->after('shasum');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['shasum', 'integrity']);
        });
    }
};
