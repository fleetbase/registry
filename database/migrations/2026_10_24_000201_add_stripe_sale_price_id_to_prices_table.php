<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A listing's sale price is a second Stripe price on the same product; `on_sale` picks which
 * one Checkout uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->string('stripe_sale_price_id')->nullable()->after('stripe_price_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropIndex(['stripe_sale_price_id']);
            $table->dropColumn('stripe_sale_price_id');
        });
    }
};
