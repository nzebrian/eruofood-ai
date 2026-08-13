<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('shared_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Scope namespaces the key per operation, so a client may reuse one
            // key value across unrelated endpoints without a false collision.
            $table->string('scope', 64);
            $table->string('idempotency_key', 255);

            // Fingerprint of the request payload. A repeat carrying the same key
            // but a different body is a client bug, not a retry, and is refused
            // rather than answered with the earlier result.
            $table->string('request_hash', 64);

            $table->uuid('user_id')->nullable()->index();

            // in_progress => claimed, still running. completed => response_snapshot
            // holds the answer to replay.
            $table->string('state', 16)->default('in_progress');
            $table->jsonb('response_snapshot')->nullable();

            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();

            // The mutex. Two simultaneous retries both attempt this insert and
            // the database picks the winner — no read-then-write window.
            $table->unique(['scope', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_idempotency_keys');
    }
};
