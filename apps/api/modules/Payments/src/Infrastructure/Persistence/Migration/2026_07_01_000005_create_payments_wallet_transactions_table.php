<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments_wallet_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id')->index();
            $table->string('type');
            $table->string('direction');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->string('currency', 3)->default('NGN');
            $table->string('reference')->nullable();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_wallet_transactions');
    }
};
