<?php

declare(strict_types=1);

use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rider is carrying this delivery — and the last line that keeps that true.
 *
 * ## The two indexes this table exists for
 *
 * **One active assignment per delivery.** Two riders arriving at one restaurant
 * for one bag of food is the single worst thing this context can do. It costs
 * the platform two payouts, embarrasses the merchant, and one rider did the
 * work for nothing.
 *
 * **One active assignment per rider.** A rider holding two deliveries they
 * accepted seconds apart cannot do both, and the customer of the second one
 * finds out late.
 *
 * Both are enforced in the application — `SELECT … FOR UPDATE` on the request,
 * optimistic versions on the aggregates. Both are enforced here as well, and
 * that redundancy is the point: the application checks are a race away from
 * failing, and a partial unique index is not. When a future refactor adds a
 * second acceptance path and forgets the lock, this is what still holds.
 *
 * ## Why partial
 *
 * A finished assignment must not block the next one. A rider who delivered at
 * noon takes another job at one; a delivery whose rider dropped out gets
 * reassigned. So the indexes cover only the states that still occupy someone —
 * exactly {@see AssignmentState::occupyingValues()}, which is generated from
 * the enum rather than retyped, so the database and the code cannot drift into
 * disagreeing about what "active" means.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dispatch_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->index();
            $table->uuid('offer_id')->unique();     // one assignment per accepted offer
            $table->uuid('delivery_id');            // soft ref to marketplace_deliveries
            $table->uuid('rider_id');
            $table->uuid('vehicle_id')->nullable();

            $table->string('state', 24)->default('accepted');
            $table->unsignedInteger('eta_seconds')->nullable();

            $table->string('ended_reason', 255)->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->timestamp('accepted_at');
            $table->timestamp('updated_at');
            $table->unsignedInteger('version')->default(1);

            // The fairness and workload reads: this rider's recent assignments,
            // newest first.
            $table->index(['rider_id', 'accepted_at'], 'dispatch_assignments_rider_history_idx');
            $table->index(['delivery_id', 'state'], 'dispatch_assignments_delivery_state_idx');
        });

        $this->addExclusivityGuarantees();
        $this->addChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_assignments');
    }

    /**
     * The guarantees that survive a bug in the application layer.
     *
     * The state list is generated from the enum, so adding a state without
     * deciding whether it occupies a rider is a decision somebody has to make
     * rather than one they can skip.
     */
    private function addExclusivityGuarantees(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $active = implode(', ', array_map(
            static fn (string $state): string => "'".$state."'",
            AssignmentState::occupyingValues(),
        ));

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX dispatch_assignments_one_active_per_delivery_uq '
            .'ON dispatch_assignments (delivery_id) WHERE state IN (%s)',
            $active,
        ));

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX dispatch_assignments_one_active_per_rider_uq '
            .'ON dispatch_assignments (rider_id) WHERE state IN (%s)',
            $active,
        ));
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $known = implode(', ', array_map(
            static fn (AssignmentState $s): string => "'".$s->value."'",
            AssignmentState::cases(),
        ));

        DB::statement(sprintf(
            'ALTER TABLE dispatch_assignments ADD CONSTRAINT dispatch_assignments_state_known '
            .'CHECK (state IN (%s))',
            $known,
        ));

        // An ended assignment records when it ended. Without it there is no way
        // to tell a delivery that finished from one that is still running, and
        // every duration metric built on this table would be wrong.
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_assignments
            ADD CONSTRAINT dispatch_assignments_ended_has_a_time
            CHECK (
                state NOT IN ('delivered', 'cancelled', 'reassignment_required')
                OR ended_at IS NOT NULL
            )
        SQL);
    }
};
