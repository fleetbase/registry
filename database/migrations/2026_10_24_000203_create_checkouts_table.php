<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A started Stripe Checkout Session. Its public id is the hand-off id the buyer's console or
 * the website polls until the `checkout.session.completed` webhook creates the purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->uuid('extension_uuid')->index();
            $table->uuid('organization_uuid')->index();
            $table->uuid('account_uuid')->nullable()->index();
            $table->uuid('instance_uuid')->nullable()->index();
            $table->string('source', 16);
            $table->string('status', 16)->default('open')->index();
            $table->bigInteger('amount')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->bigInteger('fee_amount')->default(0);
            $table->unsignedInteger('fee_rate_bps')->default(0);
            $table->json('price_snapshot')->nullable();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->uuid('purchase_uuid')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
