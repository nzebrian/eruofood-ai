<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which accruals a settlement run paid for.
 *
 * ## `unique(accrual_id)` is the single most important constraint in M27
 *
 * An accrual can appear on **one** settlement line, platform-wide, for ever.
 * That one index is what makes paying a merchant twice for the same order
 * structurally impossible — not "unlikely", not "guarded against", impossible,
 * regardless of how many workers race, which locks a future refactor forgets,
 * or how a retry is written.
 *
 * It is a plain unique index rather than a partial one, and that is a
 * deliberate difference from the runs table. A cancelled run releases its
 * accruals by having its lines **deleted**, so the accrual becomes free again;
 * the index therefore never needs to exempt a state. Making it partial would
 * have created exactly the window it exists to close: two live runs each
 * holding a line for the same accrual because one of them was briefly in an
 * excluded state.
 *
 * Lines are otherwise append-only. Deletion is permitted only as part of
 * releasing a run, and the trigger below allows it while still refusing any
 * UPDATE — a line's amount can never be edited, which is what would let a
 * payout quietly grow after approval.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments_settlement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('settlement_run_id');
            $table->uuid('accrual_id');

            $table->char('currency', 3);
            $table->bigInteger('net_minor');

            $table->timestamp('created_at');

            $table->index('settlement_run_id', 'payments_settlement_lines_run_idx');

            // The constraint. Not partial, not conditional, not deferrable.
            $table->unique('accrual_id', 'payments_settlement_lines_accrual_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payments_settlement_lines
            ADD CONSTRAINT payments_settlement_lines_amount_positive
            CHECK (net_minor > 0)
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION eruofood_settlement_lines_no_update()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'payments_settlement_lines cannot be updated. Release the run and recompute instead.'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payments_settlement_lines_no_update
            BEFORE UPDATE ON payments_settlement_lines
            FOR EACH ROW EXECUTE FUNCTION eruofood_settlement_lines_no_update();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payments_settlement_lines_no_update ON payments_settlement_lines;');
            DB::unprepared('DROP FUNCTION IF EXISTS eruofood_settlement_lines_no_update();');
        }

        Schema::dropIfExists('payments_settlement_lines');
    }
};
