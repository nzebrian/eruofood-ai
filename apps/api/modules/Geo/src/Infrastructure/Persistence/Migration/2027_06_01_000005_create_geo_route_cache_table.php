<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable route results, behind the Redis cache.
 *
 * Redis is the hot path and is deliberately allowed to be volatile. This table
 * is the second line, and it earns its place for one specific moment: the
 * provider is down, Redis has been flushed or restarted, and a customer is
 * trying to check out. A route from this morning is a defensible basis for a
 * delivery fee; a straight-line guess is not, at any age.
 *
 * `calculated_at` is what makes that judgement possible, and the read path
 * always compares it against the configured grace period rather than trusting
 * the row simply because it exists.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('geo_route_cache', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // A namespace prefix plus the sha256 of rounded origin + rounded
            // destination + mode + traffic flag. Rounding is what makes a cache
            // hit possible at all: two requests from opposite ends of the same
            // building should share an answer.
            //
            // Sized for the prefix as well as the digest. At exactly 64 this
            // column held the hash and nothing else, and every write of a
            // prefixed key failed — silently on SQLite, which truncates, and
            // loudly on PostgreSQL, which does not. The failure mode was a
            // permanent hundred-percent cache miss and a bill to match.
            $table->string('cache_key', 128)->unique();

            $table->decimal('origin_latitude', 10, 7);
            $table->decimal('origin_longitude', 10, 7);
            $table->decimal('destination_latitude', 10, 7);
            $table->decimal('destination_longitude', 10, 7);

            $table->string('travel_mode', 16);
            $table->boolean('traffic_aware')->default(false);

            $table->unsignedInteger('distance_metres');
            $table->unsignedInteger('duration_seconds');
            $table->unsignedInteger('duration_in_traffic_seconds')->nullable();

            $table->string('provider', 32);
            $table->string('provider_route_id')->nullable();

            $table->timestamp('calculated_at')->index();
            $table->timestamps();

            // The staleness sweep, and the "is there anything usable?" lookup.
            $table->index(['travel_mode', 'calculated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_route_cache');
    }
};
