<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to move money to a merchant, including the ones that failed and
 * the ones we never got an answer about.
 *
 * ## Written before the provider is called
 *
 * The row exists in state `created` before the transfer request leaves the
 * process. That ordering is the fix for the crash window that used to lose
 * money: previously a process could die after the provider accepted a transfer
 * and before anything was written, leaving funds gone with no record and
 * nothing that would ever look for them. Now the worst case is a `created` row
 * with no outcome — which reconciliation can find, and does.
 *
 * ## Append-only, and one row per attempt
 *
 * A retry is a new row with a new `attempt_no` and a new idempotency key, never
 * an edit. `unique(settlement_run_id, attempt_no)` keeps the sequence honest,
 * and `unique(provider, provider_reference)` means a provider reference can
 * only ever belong to one attempt — so two rows cannot both claim the same
 * transfer.
 *
 * ## `raw_response_digest`, not the response
 *
 * Provider payloads carry account numbers, and sometimes echo request bodies
 * containing credentials. A digest proves the response has not changed between
 * two reads without storing anything sensitive.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments_payout_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('settlement_run_id');
            $table->unsignedInteger('attempt_no');

            $table->string('provider', 32);
            $table->string('provider_reference', 190)->nullable();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            $table->string('state', 32);
            $table->string('failure_reason', 255)->nullable();

            $table->string('idempotency_key', 190);
            $table->string('correlation_id', 128)->nullable();

            // Never the payload itself. See the class docblock.
            $table->string('raw_response_digest', 64)->nullable();

            $table->timestamp('created_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('updated_at');

            $table->index(['settlement_run_id', 'state'], 'payments_payout_attempts_run_state_idx');
            $table->index('state', 'payments_payout_attempts_state_idx');

            $table->unique(['settlement_run_id', 'attempt_no'], 'payments_payout_attempts_sequence_unique');
            $table->unique('idempotency_key', 'payments_payout_attempts_idempotency_unique');
        });

        // Partial: a `created` attempt has no provider reference yet, and NULLs
        // must not collide with each other.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_payout_attempts_provider_reference_unique
            ON payments_payout_attempts (provider, provider_reference)
            WHERE provider_reference IS NOT NULL
        SQL);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payments_payout_attempts
            ADD CONSTRAINT payments_payout_attempts_amount_positive
            CHECK (amount_minor > 0)
        SQL);

        // A confirmed attempt without a provider reference would be an
        // unverifiable claim that money left.
        DB::statement(<<<'SQL'
            ALTER TABLE payments_payout_attempts
            ADD CONSTRAINT payments_payout_attempts_confirmed_has_reference
            CHECK (state <> 'confirmed' OR provider_reference IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_payout_attempts');
    }
};
