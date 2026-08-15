<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The receipt for the legacy vehicle backfill.
 *
 * A data migration that touches every existing rider needs to leave behind a
 * record of what it decided, per rider, or nobody can answer the two questions
 * that matter afterwards: *how many riders became dispatch-ineligible, and
 * why?* and *what exactly would rolling this back undo?*
 *
 * One row is written for **every** rider examined — including the ones where
 * the decision was to create nothing. The riders with no vehicle are precisely
 * the population that needs following up (they are the ones who will stop
 * receiving work), so an outcome table that only recorded successes would omit
 * the part anyone actually needs.
 *
 * This table is the backfill's undo list as well: rollback consults it rather
 * than guessing which vehicles were machine-created.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dispatch_vehicle_backfill_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('rider_id')->index();

            // Exactly what was in the legacy column, preserved verbatim. Null
            // is itself a finding: a rider row with no vehicle type at all.
            $table->string('legacy_vehicle_type')->nullable();

            // The supported type it mapped to, or null when nothing legitimate
            // could be inferred. Null is never filled with a guess.
            $table->string('mapped_type', 16)->nullable();

            $table->string('outcome', 32)->index();

            // Set when the row created needs a human before it can be verified
            // — a car with no plate on record, an unrecognised legacy string.
            $table->boolean('needs_manual_review')->default(false)->index();

            // The vehicle this run created, if it created one. The rollback key.
            $table->uuid('vehicle_id')->nullable()->index();

            $table->string('note', 255)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_vehicle_backfill_log');
    }
};
