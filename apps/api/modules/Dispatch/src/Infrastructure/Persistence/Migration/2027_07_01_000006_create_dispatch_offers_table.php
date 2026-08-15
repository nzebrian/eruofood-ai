<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One delivery, offered to one rider, for a short time.
 *
 * Two unique indexes carry two different guarantees:
 *
 * - **one live offer per rider** (partial, on `state = 'offered'`) — a rider
 *   looks at one job at a time, so they cannot accept two and then discover
 *   they can only do one;
 * - **one offer per rider per request** (total) — a rider is never re-offered a
 *   delivery they already answered. Re-asking somebody who declined wastes
 *   their attention and costs the customer another timeout window.
 *
 * Note what is deliberately *not* here: nothing stops a request having several
 * live offers out at once, because a market running broadcast offers genuinely
 * wants that. Two riders racing to accept the same delivery therefore remains
 * possible by design, and the defence against both winning lives on
 * `dispatch_assignments`, not here.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dispatch_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->index();
            $table->uuid('rider_id');
            $table->uuid('delivery_id')->index();
            $table->uuid('vehicle_id')->nullable();

            $table->decimal('score', 6, 4)->default(0);

            // The reasoning behind the score, kept with the decision. A scoring
            // system whose choices cannot be explained afterwards is one nobody
            // can debug and one no rider can be given an honest answer about.
            $table->jsonb('score_breakdown')->nullable();

            $table->unsignedInteger('eta_seconds')->nullable();
            $table->unsignedInteger('distance_metres')->nullable();

            $table->string('state', 16)->default('offered');
            $table->timestamp('responded_at')->nullable();
            $table->string('decline_reason', 255)->nullable();

            $table->timestamp('offered_at');

            // Stamped, not computed from a TTL on read. Two processes decide
            // whether an offer has run out — the rider's phone and the expiry
            // sweep — and computing it separately would let them disagree by
            // milliseconds about the same offer.
            $table->timestamp('expires_at')->index();

            $table->unsignedInteger('version')->default(1);

            $table->index(['rider_id', 'state'], 'dispatch_offers_rider_state_idx');
            $table->index(['state', 'expires_at'], 'dispatch_offers_sweep_idx');
        });

        $this->addUniquenessGuarantees();
        $this->addChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_offers');
    }

    private function addUniquenessGuarantees(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dispatch_offers_one_live_per_rider_uq
            ON dispatch_offers (rider_id)
            WHERE state = 'offered'
        SQL);

        // A rider must never be re-offered a delivery they already answered:
        // re-asking someone who declined wastes their attention and costs the
        // customer another timeout window.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dispatch_offers_one_per_rider_per_request_uq
            ON dispatch_offers (request_id, rider_id)
        SQL);
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_offers
            ADD CONSTRAINT dispatch_offers_state_known
            CHECK (state IN ('offered', 'accepted', 'declined', 'expired', 'cancelled'))
        SQL);

        // An answered offer records when. Without it there is no way to measure
        // how long riders take to respond, which is the number that decides
        // whether the TTL is right.
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_offers
            ADD CONSTRAINT dispatch_offers_answered_has_a_time
            CHECK (state = 'offered' OR responded_at IS NOT NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_offers
            ADD CONSTRAINT dispatch_offers_expiry_after_offer
            CHECK (expires_at > offered_at)
        SQL);
    }
};
