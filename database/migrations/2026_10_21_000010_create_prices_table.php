<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('extension_uuid')->unique();
            $table->string('model', 32)->default('free')->index();
            $table->bigInteger('amount')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->bigInteger('sale_amount')->nullable();
            $table->boolean('on_sale')->default(false);
            $table->string('stripe_product_id')->nullable()->index();
            $table->string('stripe_price_id')->nullable()->index();
            // Reserved for subscriptions; unused in this phase.
            $table->string('interval', 16)->nullable();
            $table->unsignedSmallInteger('interval_count')->nullable();
            $table->unsignedSmallInteger('trial_days')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
