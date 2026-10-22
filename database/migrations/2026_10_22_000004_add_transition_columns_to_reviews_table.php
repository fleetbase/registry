<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every release status change writes a `reviews` row. `from_status` and `to_status` make
 * the row a self-describing transition; `actor` says who caused it (`publisher`, `staff`
 * or `system` for the checks job and the D6 auto-approval), since `reviewer_account_uuid`
 * is null for system transitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('from_status', 32)->nullable()->after('action');
            $table->string('to_status', 32)->nullable()->after('from_status')->index();
            $table->string('actor', 16)->default('system')->after('reviewer_account_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['to_status']);
            $table->dropColumn(['from_status', 'to_status', 'actor']);
        });
    }
};
