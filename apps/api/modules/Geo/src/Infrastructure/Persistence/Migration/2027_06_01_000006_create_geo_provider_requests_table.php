<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per billable provider call — the cost and health ledger.
 *
 * Mapping APIs bill per request, and the failure mode is not a crash but an
 * invoice. Without this table the first sign of a looping mobile client is a
 * monthly bill; with it, the Global Command Centre can see call volume, cache
 * effectiveness, latency and failure rates as they happen.
 *
 * Deliberately records **no coordinates and no address text**. This is
 * operational telemetry that will be queried, exported and graphed by people
 * who have no business seeing where a particular customer lives. What was
 * asked stays in the cache; only that something was asked is recorded here.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('geo_provider_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('provider', 32)->index();
            $table->string('capability', 24)->index();  // geocode | reverse_geocode | route | matrix | autocomplete

            $table->boolean('succeeded');
            $table->boolean('served_from_cache')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();

            // A short normalised code — never the provider's message, which can
            // quote the API key, the quota project or the query itself.
            $table->string('failure_code', 48)->nullable();

            $table->timestamp('requested_at')->index();

            // Daily quota accounting and cost reporting.
            $table->index(['provider', 'capability', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_provider_requests');
    }
};
