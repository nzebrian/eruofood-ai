<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('loyalty_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->index();
            $table->string('type')->index();
            $table->integer('points');
            $table->string('reason');
            $table->string('reference')->nullable()->index();
            $table->integer('balance_after');
            $table->timestamp('created_at')->index();
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger_entries');
    }
};
