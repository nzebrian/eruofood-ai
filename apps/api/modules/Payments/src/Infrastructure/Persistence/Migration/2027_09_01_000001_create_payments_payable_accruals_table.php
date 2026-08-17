<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each merchant earned, and what refunds took back. Never edited.
 *
 * ## The constraints are the feature
 *
 * The partial unique index on `order_id` for earning rows is what makes accrual
 * idempotent. Not a check-then-insert in PHP — two concurrent deliveries of the
 * same order both read "no accrual yet" and both proceed, and only the index
 * can arbitrate that. The service catches the violation and treats it as
 * success, which is the correct reading: the row it wanted to exist does.
 *
 * It is *partial* rather than plain because refund adjustments share the order
 * id. A plain unique index would have made the first refund on an order
 * impossible to record, which is a worse bug than the one it prevents.
 *
 * `refund_id` carries its own partial unique index for the same reason at the
 * other end: a webhook that delivers the same refund twice must not reduce a
 * merchant's payable twice.
 *
 * The CHECK constraints encode arithmetic the aggregate already enforces. Both,
 * deliberately. The domain rule protects the path that goes through the
 * aggregate; the constraint protects the ones that do not — a backfill, a data
 * fix, a future service written in a hurry.
 *
 * ## Append-only
 *
 * Same trigger approach as `payments_ledger_entries`. An accrual that can be
 * updated is an accrual whose history can be rewritten to justify a payout,
 * which is precisely what an auditor looks for evidence against.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments_payable_accruals', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // 'earning' | 'refund_adjustment'
            $table->string('type', 32);

            $table->string('merchant_type', 32);
            $table->uuid('merchant_id');

            // Soft references. Marketplace/Commerce own orders; there is no FK
            // across a context boundary anywhere in this codebase.
            $table->uuid('order_id');
            $table->uuid('payment_id');
            $table->uuid('refund_id')->nullable();

            $table->char('currency', 3);

            // Signed bigint on purpose, and not only because refund rows are
            // negative: `unsignedBigInteger` is a no-op on PostgreSQL, which has
            // no unsigned types, so it reads as a guarantee it does not give —
            // the lesson from the wallet balance guard. The CHECKs below are the
            // real protection.
            $table->bigInteger('gross_minor');
            $table->bigInteger('commission_minor');
            $table->bigInteger('fee_minor');
            $table->bigInteger('net_minor');

            // The rate in force when the sale happened. Copied rather than
            // looked up later so a rate change cannot retroactively alter what
            // a merchant was owed for a past order.
            $table->integer('commission_rate_bps');

            // False during the report-only cycle: the accrual is recorded, the
            // ledger movement is not, and the row is not settleable.
            $table->boolean('ledger_posted')->default(false);

            $table->string('correlation_id', 128)->nullable();

            $table->timestamp('accrued_at');
            $table->timestamp('created_at');

            $table->index(['merchant_type', 'merchant_id', 'currency'], 'payments_payable_accruals_merchant_idx');
            $table->index(['ledger_posted', 'type'], 'payments_payable_accruals_settleable_idx');
            $table->index('payment_id', 'payments_payable_accruals_payment_idx');
            $table->index('order_id', 'payments_payable_accruals_order_idx');
        });

        // Raw DDL rather than Blueprint: partial unique indexes have no
        // Blueprint expression, and they are the reason this works identically
        // on SQLite and PostgreSQL. Both engines support `CREATE UNIQUE INDEX
        // ... WHERE`, so the fast test path proves the same guarantee the
        // production engine enforces — unlike the CHECKs below.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_payable_accruals_order_earning_unique
            ON payments_payable_accruals (order_id)
            WHERE type = 'earning'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_payable_accruals_refund_unique
            ON payments_payable_accruals (refund_id)
            WHERE refund_id IS NOT NULL
        SQL);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite cannot add a CHECK or a trigger to an existing table. The
            // fast test path relies on the domain invariants and the partial
            // indexes above; the PostgreSQL concurrency suite proves the rest.
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payments_payable_accruals
            ADD CONSTRAINT payments_payable_accruals_net_derived
            CHECK (net_minor = gross_minor - commission_minor - fee_minor)
        SQL);

        // Sign discipline per row shape. An earning that is negative, or a
        // refund adjustment that is positive, would each add money to a
        // merchant's payable that nobody decided to give them.
        DB::statement(<<<'SQL'
            ALTER TABLE payments_payable_accruals
            ADD CONSTRAINT payments_payable_accruals_signs
            CHECK (
                (type = 'earning'
                    AND gross_minor >= 0 AND commission_minor >= 0
                    AND fee_minor >= 0 AND net_minor >= 0
                    AND refund_id IS NULL)
                OR
                (type = 'refund_adjustment'
                    AND gross_minor <= 0 AND commission_minor = 0
                    AND fee_minor = 0 AND net_minor <= 0
                    AND refund_id IS NOT NULL)
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION eruofood_payable_accruals_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'payments_payable_accruals is append-only: % is not permitted. Record a compensating accrual instead.',
                    TG_OP
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payments_payable_accruals_append_only
            BEFORE UPDATE OR DELETE ON payments_payable_accruals
            FOR EACH ROW EXECUTE FUNCTION eruofood_payable_accruals_append_only();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payments_payable_accruals_append_only ON payments_payable_accruals;');
            DB::unprepared('DROP FUNCTION IF EXISTS eruofood_payable_accruals_append_only();');
        }

        Schema::dropIfExists('payments_payable_accruals');
    }
};
