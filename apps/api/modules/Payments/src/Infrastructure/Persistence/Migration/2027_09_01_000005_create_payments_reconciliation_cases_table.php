<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A disagreement somebody has to settle.
 *
 * ## The CHECK that makes "never silently corrected" true
 *
 * `payments_reconciliation_cases_adjusted_requires_evidence` refuses to store a
 * case in state `resolved_adjusted` unless it carries both a named approver and
 * the id of a compensating ledger posting.
 *
 * Without it, "a discrepancy must never be silently auto-corrected" would be a
 * sentence in a service that a later edit could delete. With it, the closest
 * anybody can get to silently closing a case is to leave it open — which is
 * visible, and is the point.
 *
 * ## One open case per subject
 *
 * A partial unique index on `(kind, subject_type, subject_id)` for unresolved
 * states. A reconciler that runs every fifteen minutes against a discrepancy
 * that takes two days to resolve would otherwise open two hundred cases for one
 * problem, and the queue would become useless exactly when it mattered.
 *
 * `subject_id` is NOT NULL specifically so that index works — see the column.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments_reconciliation_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('kind', 48);
            $table->string('subject_type', 48);

            /*
             * A string, and NOT NULL, and neither is incidental.
             *
             * A nullable subject id looked right — a platform-wide drift is
             * about no particular row — and it silently defeated the whole
             * point of the unique index below: in both SQLite and PostgreSQL
             * two NULLs are not equal, so nothing collides, and a reconciler
             * sweeping every fifteen minutes against a drift nobody has fixed
             * yet would open a fresh case every sweep until the queue was
             * useless. Platform-level cases therefore carry a stable literal
             * subject ('merchant_payable', 'ledger') rather than a null.
             *
             * A string rather than a uuid for the same reason: those literals
             * are not uuids, and a uuid column would have forced the null back.
             */
            $table->string('subject_id', 64);

            $table->bigInteger('expected_minor');
            $table->bigInteger('observed_minor');
            $table->char('currency', 3);

            $table->string('state', 32);

            $table->string('detail', 500)->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->string('resolution_note', 500)->nullable();

            // The ledger correlation id of the compensating posting. A soft
            // reference rather than an FK, matching how the ledger is
            // referenced everywhere else.
            $table->string('compensating_posting_id', 64)->nullable();

            $table->string('correlation_id', 128)->nullable();
            $table->integer('version')->default(0);

            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index(['state', 'kind'], 'payments_reconciliation_cases_state_kind_idx');
            $table->index(['subject_type', 'subject_id'], 'payments_reconciliation_cases_subject_idx');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_reconciliation_cases_open_subject_unique
            ON payments_reconciliation_cases (kind, subject_type, subject_id)
            WHERE state NOT IN ('resolved_matched', 'resolved_adjusted')
        SQL);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payments_reconciliation_cases
            ADD CONSTRAINT payments_reconciliation_cases_adjusted_requires_evidence
            CHECK (
                state <> 'resolved_adjusted'
                OR (resolved_by IS NOT NULL AND compensating_posting_id IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payments_reconciliation_cases
            ADD CONSTRAINT payments_reconciliation_cases_resolved_has_timestamp
            CHECK (
                state NOT IN ('resolved_matched', 'resolved_adjusted')
                OR resolved_at IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments_reconciliation_cases');
    }
};
