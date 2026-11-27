<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique();
            $table->uuid('extension_uuid')->index();
            $table->uuid('buyer_organization_uuid')->index();
            $table->uuid('buyer_account_uuid')->nullable()->index();
            $table->json('price_snapshot')->nullable();
            $table->bigInteger('amount_total')->default(0);
            $table->bigInteger('fee_amount')->default(0);
            $table->bigInteger('net_amount')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('status', 32)->default('paid')->index();
            $table->string('receipt_url')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
