<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One delivery's search for a rider.
 *
 * Deliberately *not* the delivery. `marketplace_deliveries` remains the
 * operational delivery record (M26 decision 1); `delivery_id` here is a soft
 * reference and every column beside it describes the search, not the delivery.
 * Duplicating a fee or a status here would create a second version of the truth
 * that could disagree with the first.
 *
 * The partial unique index is the load-bearing part of the table: **one live
 * search per delivery.** Without it, two workers picking up the same delivery —
 * a retry, a queue redelivery, an operator clicking twice — would each open a
 * request, each offer a rider, and two riders would arrive at one restaurant.
 * The application avoids that; this makes it impossible.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dispatch_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('delivery_id');   // soft ref to marketplace_deliveries
            $table->uuid('order_id');      // soft ref to commerce orders
            $table->uuid('vendor_id');     // soft ref to marketplace_vendors

            // Copied at open time on purpose: the search must stay reproducible
            // from the record even if the merchant later edits their address.
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->decimal('dropoff_lat', 10, 7);
            $table->decimal('dropoff_lng', 10, 7);

            $table->string('required_vehicle_type', 16)->default('bike');
            $table->unsignedInteger('load_kg')->nullable();
            $table->unsignedInteger('load_litres')->nullable();
            $table->uuid('zone_id')->nullable();  // soft ref to geo_delivery_zones

            $table->string('state', 16)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts');

            $table->uuid('assigned_rider_id')->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->string('failure_reason', 32)->nullable();
            $table->timestamp('failed_at')->nullable();

            // Fixed at open time, never recomputed. A deadline that moves is not
            // a deadline, and the customer is the one waiting it out.
            $table->timestamp('expires_at')->index();

            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            // The worker queue read: claimable requests, oldest first.
            $table->index(['state', 'created_at'], 'dispatch_requests_queue_idx');
            $table->index('assigned_rider_id', 'dispatch_requests_rider_idx');
            $table->index('order_id', 'dispatch_requests_order_idx');
        });

        $this->addUniquenessGuarantees();
        $this->addChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_requests');
    }

    /**
     * One live search per delivery.
     *
     * Partial, so a delivery whose first search failed can be searched for
     * again — reassignment after a rider drops out opens a *new* request rather
     * than reopening the old one, which keeps the record of what was tried
     * readable.
     */
    private function addUniquenessGuarantees(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dispatch_requests_one_live_per_delivery_uq
            ON dispatch_requests (delivery_id)
            WHERE state IN ('pending', 'dispatching')
        SQL);
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_requests
            ADD CONSTRAINT dispatch_requests_state_known
            CHECK (state IN ('pending', 'dispatching', 'assigned', 'failed', 'cancelled'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_requests
            ADD CONSTRAINT dispatch_requests_vehicle_type_known
            CHECK (required_vehicle_type IN ('bike', 'tricycle', 'car', 'bus'))
        SQL);

        // An assigned request names its rider and when. A request that says
        // "assigned" with no rider is the shape of a lost update, and it should
        // not be storable.
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_requests
            ADD CONSTRAINT dispatch_requests_assigned_names_a_rider
            CHECK (
                state <> 'assigned'
                OR (assigned_rider_id IS NOT NULL AND assigned_at IS NOT NULL)
            )
        SQL);

        // And a failure says why. "Failed" with no reason is exactly the
        // unhelpful answer this context exists to avoid giving.
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_requests
            ADD CONSTRAINT dispatch_requests_failure_has_a_reason
            CHECK (
                state NOT IN ('failed', 'cancelled')
                OR (failure_reason IS NOT NULL AND failed_at IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_requests
            ADD CONSTRAINT dispatch_requests_attempts_within_budget
            CHECK (attempt_count <= max_attempts)
        SQL);

        foreach ([['pickup_lat', 'dropoff_lat', 90], ['pickup_lng', 'dropoff_lng', 180]] as [$a, $b, $bound]) {
            DB::statement(sprintf(
                'ALTER TABLE dispatch_requests ADD CONSTRAINT dispatch_requests_%s_range '
                .'CHECK (%s BETWEEN %d AND %d AND %s BETWEEN %d AND %d)',
                $a,
                $a,
                -$bound,
                $bound,
                $b,
                -$bound,
                $bound,
            ));
        }
    }
};
