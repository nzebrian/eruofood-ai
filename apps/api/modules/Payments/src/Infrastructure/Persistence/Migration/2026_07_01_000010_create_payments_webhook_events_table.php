<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('event_id');
            $table->string('type');
            $table->timestamp('created_at');

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_webhook_events');
    }
};
