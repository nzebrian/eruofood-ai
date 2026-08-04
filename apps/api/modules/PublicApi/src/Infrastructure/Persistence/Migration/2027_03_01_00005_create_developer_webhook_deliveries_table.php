<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('developer_webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('webhook_id')->index();
            $table->string('event_id');
            $table->string('event_name')->index();
            $table->text('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();

            // Idempotency: one delivery per (webhook, source event).
            $table->unique(['webhook_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_webhook_deliveries');
    }
};
