<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('payee_type')->index();
            $table->uuid('payee_id')->index();
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('commission_minor');
            $table->unsignedBigInteger('fees_minor');
            $table->unsignedBigInteger('net_minor');
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('pending')->index();
            $table->uuid('payout_id')->nullable();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_settlements');
    }
};
