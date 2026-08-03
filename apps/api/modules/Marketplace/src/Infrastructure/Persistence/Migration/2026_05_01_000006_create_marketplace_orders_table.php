<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->uuid('customer_user_id')->index();
            $table->uuid('vendor_id')->index();
            $table->jsonb('lines')->default('[]');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('delivery_fee_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->string('currency', 3)->default('NGN');
            $table->string('fulfilment');
            $table->jsonb('delivery_address')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('pending')->index();
            $table->jsonb('status_history')->default('[]');
            $table->timestamp('placed_at');
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['customer_user_id', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_orders');
    }
};
