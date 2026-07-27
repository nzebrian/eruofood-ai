<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('payee_type')->index();
            $table->uuid('payee_id')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');
            $table->jsonb('destination')->default('{}');
            $table->string('status')->default('pending')->index();
            $table->string('provider_reference')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_payouts');
    }
};
