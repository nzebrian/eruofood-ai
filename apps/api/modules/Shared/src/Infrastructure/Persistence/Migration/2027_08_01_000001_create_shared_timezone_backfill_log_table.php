<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail for the UTC cutover.
 *
 * The backfill that follows rewrites every stored timestamp on the platform.
 * An operation like that needs to leave evidence: which table, which column,
 * how many rows, in which direction, and when. Without it, "did the cutover
 * run, and did it run once?" becomes unanswerable a week later — and the one
 * unrecoverable mistake here is applying the shift twice.
 *
 * This table is also the guard. The backfill refuses to run if rows for the
 * forward direction already exist, so a re-run of an already-applied migration
 * cannot double-shift the database.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('shared_timezone_backfill_log', function (Blueprint $table): void {
            $table->id();

            $table->string('table_name', 128);
            $table->string('column_name', 128);

            // 'forward' = local wall-clock → UTC (the cutover).
            // 'reverse' = the rollback, recorded with equal weight so an
            // operator reading this table can see the database was put back.
            $table->string('direction', 16);

            $table->integer('offset_seconds');
            $table->integer('rows_affected');

            // Deliberately the database's own clock rather than the
            // application's: this row describes an event that happened *during*
            // the change of what application time means.
            $table->timestamp('recorded_at');

            $table->index(['direction', 'table_name'], 'shared_tz_backfill_direction_table_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_timezone_backfill_log');
    }
};
