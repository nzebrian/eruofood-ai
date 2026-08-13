<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the append-only ledger in the database rather than by convention.
 *
 * `payments_ledger_entries` has always been insert-only in practice — it carries
 * no `updated_at` and no code updates it — but nothing *stopped* an update. For
 * a financial record that distinction matters: an auditor cannot be offered
 * tamper-evidence backed by "we checked that nobody wrote that code".
 *
 * The trigger rejects UPDATE and DELETE outright, so the only way to correct a
 * posting is the accounting-correct one: post a reversing entry.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION eruofood_ledger_is_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'payments_ledger_entries is append-only: % is not permitted. Post a reversing entry instead.',
                    TG_OP
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payments_ledger_entries_append_only
            BEFORE UPDATE OR DELETE ON payments_ledger_entries
            FOR EACH ROW EXECUTE FUNCTION eruofood_ledger_is_append_only();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The trigger blocks row DML, not DDL, so it can always be dropped.
        DB::unprepared('DROP TRIGGER IF EXISTS payments_ledger_entries_append_only ON payments_ledger_entries;');
        DB::unprepared('DROP FUNCTION IF EXISTS eruofood_ledger_is_append_only();');
    }
};
