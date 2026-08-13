<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('verification_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 16);
            $table->string('provider_event_id');

            // Which signature scheme actually proved the payload — worth keeping,
            // because a sudden shift in scheme is itself a signal.
            $table->string('signature_scheme', 16);

            $table->timestamp('received_at');

            // The mutex for exactly-once processing, mirroring
            // payments_webhook_events.
            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_webhook_events');
    }
};
