<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_id')->index();
            $table->uuid('order_id')->nullable()->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');
            $table->boolean('partial')->default(false);
            $table->text('reason');
            $table->string('status')->default('pending')->index();
            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_refunds');
    }
};
