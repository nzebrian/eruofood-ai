<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('correlation_id')->index();
            $table->string('account')->index();
            $table->string('direction');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');
            $table->string('type');
            $table->string('reference')->nullable();
            $table->timestamp('posted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_ledger_entries');
    }
};
