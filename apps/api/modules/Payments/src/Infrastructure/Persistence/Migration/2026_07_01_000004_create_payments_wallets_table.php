<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments_wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type')->index();
            $table->uuid('owner_id')->index();
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->unsignedBigInteger('low_balance_threshold')->default(0);
            $table->timestamp('created_at');

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_wallets');
    }
};
