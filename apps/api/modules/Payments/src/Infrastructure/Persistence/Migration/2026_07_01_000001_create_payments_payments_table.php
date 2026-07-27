<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->uuid('order_id')->nullable()->index(); // opaque soft ref — no FK to Order
            $table->uuid('payer_user_id')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('pending')->index();
            $table->string('provider')->index();
            $table->string('method_type');
            $table->string('provider_reference')->nullable()->index();
            $table->string('idempotency_key')->unique();
            $table->jsonb('splits')->default('[]');
            $table->text('failure_reason')->nullable();
            $table->jsonb('timeline')->default('[]');
            $table->timestamp('created_at');

            $table->index(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_payments');
    }
};
