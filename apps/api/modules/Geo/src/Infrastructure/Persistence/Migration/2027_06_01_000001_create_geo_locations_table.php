<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's one geographic record.
 *
 * Every address in EruoFood points here: customer delivery addresses,
 * restaurant and grocery trading addresses, delivery-zone centres. Before M25
 * each context modelled its own address with different fields and different
 * assumptions, so "where is this?" had several answers.
 *
 * `decimal(10,7)` rather than a float: ~11 mm of precision, exact round-trips,
 * and no float-comparison surprises when checking whether a stored point
 * matches the one just computed. It also matches the columns that already
 * exist on vendors, riders and search documents, so nothing has to be
 * converted.
 *
 * PostGIS is deliberately not used. Proximity is served by a bounding-box
 * prefilter over the composite index below plus an exact haversine pass in
 * PHP — the pattern `EloquentVendorRepository` already uses successfully. All
 * spatial reads go through a repository seam, so PostGIS can be adopted later
 * as an adapter rather than a rewrite.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('geo_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // ---- the address, in country-neutral terms ----
            $table->text('address_text')->nullable();       // as entered
            $table->text('formatted_address')->nullable();  // as the provider returned it
            $table->string('line1')->nullable();
            $table->string('line2')->nullable();
            $table->string('district')->nullable();         // neighbourhood / area
            $table->string('locality')->nullable();         // city / town
            $table->string('admin_area')->nullable();       // state / province
            $table->string('sub_admin_area')->nullable();   // LGA / county
            $table->string('postal_code', 32)->nullable();  // optional: unreliable in NG
            $table->char('country_code', 2)->nullable();    // ISO-3166-1 alpha-2
            $table->string('country_name')->nullable();

            // ---- the point ----
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // How the point was obtained and how exact it is. Stored together
            // because they are only meaningful together: the same coordinates
            // can be a rooftop or the centre of a city, and routing a rider to
            // the second is a different kind of mistake.
            $table->string('source', 16)->default('manual');
            $table->string('precision', 24)->default('unknown');
            $table->decimal('confidence', 3, 2)->nullable();

            $table->string('verification_status', 16)->default('unverified')->index();

            $table->string('provider', 32)->nullable();
            $table->string('provider_place_id')->nullable();

            $table->timestamp('geocoded_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            // Bounding-box proximity: the query narrows on this index, then the
            // survivors are measured exactly.
            $table->index(['latitude', 'longitude']);
            $table->index(['country_code', 'admin_area']);
            $table->index('provider_place_id');
        });

        $this->addCoordinateChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_locations');
    }

    /**
     * Range constraints at the database.
     *
     * The domain rejects impossible coordinates at construction, but M24 taught
     * the lesson that an application-only guarantee is not a guarantee: a raw
     * insert, a migration backfill or a future service bypasses it. PostgreSQL
     * gets real CHECK constraints; SQLite cannot add them via ALTER TABLE, so
     * there the domain rules and their tests are the guarantee, and the
     * production engine carries the enforcement.
     */
    private function addCoordinateChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE geo_locations
            ADD CONSTRAINT geo_locations_latitude_range
            CHECK (latitude IS NULL OR (latitude >= -90 AND latitude <= 90))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geo_locations
            ADD CONSTRAINT geo_locations_longitude_range
            CHECK (longitude IS NULL OR (longitude >= -180 AND longitude <= 180))
        SQL);

        // Half a coordinate is not a place. Permitting it would let a row claim
        // a latitude with no longitude and quietly fail every distance check.
        DB::statement(<<<'SQL'
            ALTER TABLE geo_locations
            ADD CONSTRAINT geo_locations_coordinates_paired
            CHECK ((latitude IS NULL) = (longitude IS NULL))
        SQL);
    }
};
