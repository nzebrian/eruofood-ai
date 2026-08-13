<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes an overdrawn wallet impossible at the storage layer.
 *
 * `balance_minor` was declared `unsignedBigInteger`, which reads as a guarantee
 * — but PostgreSQL has no unsigned integer type, so Laravel emits a plain signed
 * `bigint` and the production engine has always accepted a negative balance. The
 * only thing standing between a bug and a negative wallet was application code.
 *
 * A CHECK constraint closes that gap for good: no code path, migration, console
 * command or manual SQL can push a wallet below zero. The domain invariant and
 * the database now agree.
 */
return new class () extends Migration {
    private const CONSTRAINT = 'payments_wallets_balance_non_negative';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // SQLite cannot add a CHECK to an existing table; the test-path guarantee
        // comes from the domain invariant plus the PostgreSQL-backed concurrency
        // suite, which is where a race can actually occur.
        if ($driver !== 'pgsql') {
            return;
        }

        $offending = (int) DB::table('payments_wallets')->where('balance_minor', '<', 0)->count();
        if ($offending > 0) {
            throw new RuntimeException(sprintf(
                'Cannot add the wallet balance guard: %d wallet(s) already hold a negative balance. '
                .'Reconcile them before deploying this migration.',
                $offending,
            ));
        }

        DB::statement(sprintf(
            'ALTER TABLE payments_wallets ADD CONSTRAINT %s CHECK (balance_minor >= 0)',
            self::CONSTRAINT,
        ));
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf('ALTER TABLE payments_wallets DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));
    }
};
