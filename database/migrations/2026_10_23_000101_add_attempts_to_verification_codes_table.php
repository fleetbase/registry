<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wrong guesses against a live code. Verification and password-reset codes stop working after
 * VerificationCode::MAX_ATTEMPTS wrong tries, so a six-digit code cannot be guessed within its
 * lifetime even across many IP addresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
