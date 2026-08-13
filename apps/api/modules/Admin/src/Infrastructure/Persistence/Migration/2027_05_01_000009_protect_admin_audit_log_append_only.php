<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the admin audit trail append-only in the database.
 *
 * M24 routes every read of regulated identity data into this table, which
 * raises the bar it has to meet. The compliance requirement is not "an access
 * is recorded" but "an access cannot be un-recorded": a privileged reader who
 * can delete the row proving they looked has, in effect, not been audited at
 * all — and privileged readers are precisely the ones this trail exists to
 * cover.
 *
 * Same protection and same reasoning as the financial ledger in M23 and the
 * verification event trail: corrections are appended, never rewritten.
 */
return new class () extends Migration {
    public function up(): void
    {
        // PostgreSQL-only object. SQLite has no equivalent, and faking one there
        // would assert a protection the test engine does not provide.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION eruofood_admin_audit_log_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'admin_audit_log is append-only: % is not permitted. Append a corrective entry instead.',
                    TG_OP
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER admin_audit_log_append_only
            BEFORE UPDATE OR DELETE ON admin_audit_log
            FOR EACH ROW EXECUTE FUNCTION eruofood_admin_audit_log_append_only();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS admin_audit_log_append_only ON admin_audit_log;');
        DB::unprepared('DROP FUNCTION IF EXISTS eruofood_admin_audit_log_append_only();');
    }
};
