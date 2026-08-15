<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a rider actually works on.
 *
 * Before M26 the entire answer was `marketplace_riders.vehicle_type` — one
 * free-form string, no capacity, no paperwork, nobody's signature against it.
 * Dispatch cannot match a load to a string, and no operator could answer "is
 * this rider insured?" without telephoning them.
 *
 * Three columns carry three different failures, deliberately not collapsed:
 * `status` (may it be used now), `verification_state` (has a human checked the
 * papers), and the expiry dates (are those papers still current). A verified
 * vehicle whose insurance lapsed last night is still verified — its documents
 * simply are not current, and dispatch stops offering it work at the moment
 * the policy lapses rather than whenever somebody next looks.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dispatch_vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('rider_id');  // soft ref to marketplace_riders

            $table->string('type', 16);

            // Nullable because a bicycle has no plate. The domain requires one
            // for every type that legally needs it; see VehicleType::requiresRegistration().
            $table->string('registration_number', 32)->nullable();
            $table->string('make', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('colour', 32)->nullable();

            // What this vehicle can actually take. Null means "use the type's
            // default" rather than "unlimited" — an absent number must never
            // read as permission to load anything onto it.
            $table->unsignedInteger('capacity_kg')->nullable();
            $table->unsignedInteger('capacity_litres')->nullable();

            $table->string('status', 24)->default('pending_verification');
            $table->string('verification_state', 16)->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->uuid('verified_by')->nullable();       // soft ref to admin_accounts
            $table->string('verification_note', 500)->nullable();

            $table->timestamp('insurance_expires_at')->nullable();
            $table->timestamp('roadworthiness_expires_at')->nullable();
            $table->timestamp('licence_expires_at')->nullable();

            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            // Optimistic locking, the M23 pattern. Two operators approving the
            // same vehicle, or a rider editing documents while an operator
            // approves them, must not silently overwrite one another.
            $table->unsignedInteger('version')->default(1);

            // The dispatch read: "this rider's usable vehicles". Ordered
            // rider-first because that is the only way it is ever queried.
            $table->index(['rider_id', 'status'], 'dispatch_vehicles_rider_status_idx');

            // The operator verification queue.
            $table->index(['verification_state', 'created_at'], 'dispatch_vehicles_queue_idx');

            // The nightly expiry sweep.
            $table->index('insurance_expires_at', 'dispatch_vehicles_insurance_expiry_idx');
        });

        $this->addUniquenessGuarantees();
        $this->addChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_vehicles');
    }

    /**
     * The constraints the application cannot be trusted to keep on its own.
     *
     * A registration number identifies a vehicle to the state; two riders
     * claiming the same plate is either a data-entry error or somebody using a
     * colleague's papers to pass verification. Either way the database should
     * refuse it rather than leave it to a service to remember to check.
     *
     * Both are *partial*: retired vehicles are excluded, so a rider who sells a
     * motorbike does not permanently poison that plate for its next owner, and
     * a rider may retire a vehicle and register a replacement as primary.
     */
    private function addUniquenessGuarantees(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX dispatch_vehicles_active_registration_uq
                ON dispatch_vehicles (registration_number)
                WHERE registration_number IS NOT NULL AND status <> 'retired'
            SQL);

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX dispatch_vehicles_one_primary_uq
                ON dispatch_vehicles (rider_id)
                WHERE is_primary = true AND status <> 'retired'
            SQL);

            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite supports partial indexes with the same semantics, so the
            // fast test path exercises the real guarantee rather than a
            // weakened stand-in that would let a bug through locally and fail
            // in production.
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX dispatch_vehicles_active_registration_uq
                ON dispatch_vehicles (registration_number)
                WHERE registration_number IS NOT NULL AND status <> 'retired'
            SQL);

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX dispatch_vehicles_one_primary_uq
                ON dispatch_vehicles (rider_id)
                WHERE is_primary = 1 AND status <> 'retired'
            SQL);
        }
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The enum, restated where the data lives. An application deploy that
        // introduced a fifth vehicle type without a migration would be caught
        // here rather than discovered by a dispatch engine that silently
        // matched nothing.
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_vehicles
            ADD CONSTRAINT dispatch_vehicles_type_known
            CHECK (type IN ('bike', 'tricycle', 'car', 'bus'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_vehicles
            ADD CONSTRAINT dispatch_vehicles_status_known
            CHECK (status IN ('pending_verification', 'active', 'suspended', 'retired'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_vehicles
            ADD CONSTRAINT dispatch_vehicles_verification_state_known
            CHECK (verification_state IN ('unverified', 'pending', 'verified', 'rejected', 'expired'))
        SQL);

        // A vehicle cannot be active without having been verified. This is the
        // single most consequential rule in the table — it is what stops an
        // unchecked vehicle receiving work — so it is enforced by the database
        // and not only by the aggregate.
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_vehicles
            ADD CONSTRAINT dispatch_vehicles_active_requires_verified
            CHECK (status <> 'active' OR verification_state = 'verified')
        SQL);

        // A verified vehicle carries who verified it and when. Without both,
        // "verified" is an assertion nobody signed.
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_vehicles
            ADD CONSTRAINT dispatch_vehicles_verified_is_attributed
            CHECK (
                verification_state <> 'verified'
                OR (verified_at IS NOT NULL AND verified_by IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_vehicles
            ADD CONSTRAINT dispatch_vehicles_capacity_positive
            CHECK (
                (capacity_kg IS NULL OR capacity_kg > 0)
                AND (capacity_litres IS NULL OR capacity_litres > 0)
            )
        SQL);
    }
};
