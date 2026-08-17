<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One settlement of one merchant over one window.
 *
 * ## The partial unique index is the last line
 *
 * `payments_settlement_runs_live_window_unique` permits at most one *live* run
 * per merchant and window. Cancelled, failed and reversed runs are excluded, so
 * a genuine second attempt after a refusal is allowed while a duplicate
 * submission is not.
 *
 * It is there so that the guarantee survives a refactor. The service takes a
 * row lock, re-reads state inside it, and carries an optimistic version — three
 * layers that all depend on somebody remembering to write them. This index does
 * not: a future service that forgets every one of them still cannot create two
 * live runs for the same window, because the database will not hold them.
 *
 * Partial indexes work identically on SQLite and PostgreSQL, so the fast test
 * path proves the same rule production enforces. That is why the guarantee is
 * an index and not a CHECK.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments_settlement_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('merchant_type', 32);
            $table->uuid('merchant_id');
            $table->char('currency', 3);

            $table->timestamp('window_start');
            $table->timestamp('window_end');

            $table->bigInteger('gross_minor');
            $table->bigInteger('commission_minor');
            $table->bigInteger('fee_minor');
            $table->bigInteger('net_minor');

            $table->string('state', 32);

            // Client-supplied where there is a client; server-derived for the
            // scheduled path. Unique either way.
            $table->string('idempotency_key', 190)->nullable();

            // Deterministic, provider-safe, and the reference the provider sees.
            $table->string('settlement_reference', 64);

            $table->string('correlation_id', 128)->nullable();

            // Separation of duties lives in these two pairs: the actor who
            // approves is compared against the actor who executes, and the
            // domain refuses them if they match.
            $table->uuid('computed_by')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->string('failure_reason', 255)->nullable();

            // Optimistic concurrency, matching payments_wallets.
            $table->integer('version')->default(0);

            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index(['merchant_type', 'merchant_id', 'state'], 'payments_settlement_runs_merchant_state_idx');
            $table->index('state', 'payments_settlement_runs_state_idx');
            $table->unique('settlement_reference', 'payments_settlement_runs_reference_unique');
        });

        // Raw DDL: partial and conditional unique indexes have no Blueprint
        // expression, and both engines support them.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_settlement_runs_idempotency_unique
            ON payments_settlement_runs (idempotency_key)
            WHERE idempotency_key IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_settlement_runs_live_window_unique
            ON payments_settlement_runs (merchant_type, merchant_id, currency, window_start, window_end)
            WHERE state NOT IN ('cancelled', 'failed', 'reversed')
        SQL);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payments_settlement_runs
            ADD CONSTRAINT payments_settlement_runs_net_derived
            CHECK (net_minor = gross_minor - commission_minor - fee_minor)
        SQL);

        // A run that pays out nothing, or less than nothing, is not a run.
        DB::statement(<<<'SQL'
            ALTER TABLE payments_settlement_runs
            ADD CONSTRAINT payments_settlement_runs_amounts_non_negative
            CHECK (gross_minor >= 0 AND commission_minor >= 0 AND fee_minor >= 0 AND net_minor >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payments_settlement_runs
            ADD CONSTRAINT payments_settlement_runs_window_ordered
            CHECK (window_end > window_start)
        SQL);

        // Nothing past Draft may exist without a named approver. The domain
        // refuses it too; this catches the paths that never reach the domain.
        DB::statement(<<<'SQL'
            ALTER TABLE payments_settlement_runs
            ADD CONSTRAINT payments_settlement_runs_approved_before_execution
            CHECK (
                state IN ('draft', 'cancelled')
                OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)
            )
        SQL);

        // Separation of duties, in the schema rather than only in the service.
        DB::statement(<<<'SQL'
            ALTER TABLE payments_settlement_runs
            ADD CONSTRAINT payments_settlement_runs_four_eyes
            CHECK (executed_by IS NULL OR approved_by IS NULL OR executed_by <> approved_by)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_settlement_runs');
    }
};
