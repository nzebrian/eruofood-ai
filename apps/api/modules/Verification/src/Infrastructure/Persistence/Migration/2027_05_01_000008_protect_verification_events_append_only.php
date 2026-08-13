<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the verification audit trail append-only in the database.
 *
 * This is the same protection M23 gave the financial ledger, applied for the
 * same reason: an audit trail that can be edited is not evidence. "Why is this
 * rider verified?" and "who approved this merchant, and on what grounds?" are
 * questions a regulator can ask years later, and the answer must not depend on
 * nobody having written an UPDATE.
 *
 * A correction is made by appending a further transition, never by rewriting
 * history — which is also what keeps the sequence of events truthful.
 */
return new class () extends Migration {
    public function up(): void
    {
        // PostgreSQL-only object. SQLite has no equivalent, and faking one there
        // would assert a protection the test engine does not actually provide;
        // the guarantee is verified on the production engine instead.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION eruofood_verification_events_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'verification_events is append-only: % is not permitted. Append a further transition instead.',
                    TG_OP
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER verification_events_append_only
            BEFORE UPDATE OR DELETE ON verification_events
            FOR EACH ROW EXECUTE FUNCTION eruofood_verification_events_append_only();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The trigger blocks row DML, not DDL, so it can always be dropped.
        DB::unprepared('DROP TRIGGER IF EXISTS verification_events_append_only ON verification_events;');
        DB::unprepared('DROP FUNCTION IF EXISTS eruofood_verification_events_append_only();');
    }
};
