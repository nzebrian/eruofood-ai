<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('commerce_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->uuid('customer_user_id')->index();
            $table->jsonb('store_ids')->default('[]'); // denormalised for seller queries
            $table->jsonb('lines')->default('[]');
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('coupon_code')->nullable();
            $table->boolean('pickup')->default(false);
            $table->jsonb('shipping_address')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('pending')->index();
            $table->jsonb('status_history')->default('[]');
            $table->timestamp('placed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_orders');
    }
};
