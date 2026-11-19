<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleetbase's facilitator fee with history: the row with the latest `effective_from` that has
 * passed is in effect. Rows are never edited, so past purchases keep the fee they paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilitator_fees', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('rate_bps');
            $table->timestamp('effective_from')->index();
            $table->string('note')->nullable();
            $table->uuid('created_by_account_uuid')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facilitator_fees');
    }
};
