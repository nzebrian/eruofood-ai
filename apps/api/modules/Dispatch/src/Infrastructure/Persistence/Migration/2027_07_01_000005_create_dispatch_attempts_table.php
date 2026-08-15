<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why each round of looking went the way it did.
 *
 * "No riders available" is useless to an operator at 8pm on a Friday. It could
 * mean the fleet is busy, that nobody's app is reporting a position, or that
 * eleven riders nearby all have lapsed insurance — three different problems
 * with three different responses, and the difference between a platform outage
 * and a paperwork backlog.
 *
 * `rejection_breakdown` is what tells them apart, and it is the most useful
 * column in this context when dispatch is struggling.
 *
 * Append-only, and enforced as such on PostgreSQL by the same trigger pattern
 * the admin audit log uses. A dispatch history that can be tidied up after the
 * fact is not a history.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dispatch_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->index();

            $table->unsignedSmallInteger('attempt_number');
            $table->unsignedInteger('search_radius_metres');

            // Positions the geographic pass returned, and how many survived
            // eligibility. The gap between the two is the whole story.
            $table->unsignedInteger('raw_candidate_count')->default(0);
            $table->unsignedInteger('eligible_candidate_count')->default(0);

            // reason => rider count. jsonb on PostgreSQL so it can be queried
            // and aggregated across attempts, not just read one row at a time.
            $table->jsonb('rejection_breakdown')->nullable();

            $table->uuid('offered_rider_id')->nullable();
            $table->decimal('offered_score', 6, 4)->nullable();

            $table->string('outcome', 32)->nullable();

            // How long the round took. A dispatch engine that quietly grows
            // from 200ms to 4s is a customer-visible regression nobody notices
            // without this.
            $table->unsignedInteger('duration_ms')->default(0);

            $table->timestamp('started_at');
            $table->timestamp('completed_at');

            $table->unique(['request_id', 'attempt_number'], 'dispatch_attempts_request_number_uq');
            $table->index('completed_at', 'dispatch_attempts_completed_idx');
        });

        $this->protectAppendOnly();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS dispatch_attempts_append_only ON dispatch_attempts');
            DB::statement('DROP FUNCTION IF EXISTS eruofood_dispatch_attempts_append_only()');
        }

        Schema::dropIfExists('dispatch_attempts');
    }

    private function protectAppendOnly(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION eruofood_dispatch_attempts_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'dispatch_attempts is append-only: % is not permitted. Record a further attempt instead.',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER dispatch_attempts_append_only
            BEFORE UPDATE OR DELETE ON dispatch_attempts
            FOR EACH ROW EXECUTE FUNCTION eruofood_dispatch_attempts_append_only();
        SQL);
    }
};
