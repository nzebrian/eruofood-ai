<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Areas a merchant or the platform will deliver to.
 *
 * Marketplace already had radius-only zones held as jsonb on the vendor. This
 * promotes them to first-class rows and adds polygons, because a circle
 * describes a real service area badly — a lagoon, a gated estate or a bridge
 * makes "within 5 km" and "reachable" very different sets.
 *
 * `fee_minor` and `min_order_minor` are stored but not yet authoritative: M26
 * pricing will read them. Carrying them now means the zone model does not have
 * to change when it does.
 *
 * Polygons are GeoJSON in jsonb, with containment tested in PHP. At the zone
 * counts involved that is comfortably fast, and it keeps PostGIS out of M25 —
 * the storage format converts directly to `geography(Polygon,4326)` if and when
 * that changes.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('geo_delivery_zones', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Polymorphic by intent: a vendor's own zone, a store's, or a
            // platform-wide service area. Soft reference, no cross-context FK.
            $table->string('owner_type', 16);   // vendor | store | platform
            $table->uuid('owner_id')->nullable();

            $table->string('name');
            $table->string('zone_type', 16)->default('radius');

            // Radius zones.
            $table->uuid('centre_location_id')->nullable();
            $table->decimal('centre_latitude', 10, 7)->nullable();
            $table->decimal('centre_longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_metres')->nullable();

            // Polygon zones: a GeoJSON ring, plus a cached bounding box so a
            // cheap index lookup can rule most candidates out before any
            // point-in-polygon arithmetic runs.
            $table->jsonb('polygon')->nullable();
            $table->decimal('bbox_min_lat', 10, 7)->nullable();
            $table->decimal('bbox_max_lat', 10, 7)->nullable();
            $table->decimal('bbox_min_lon', 10, 7)->nullable();
            $table->decimal('bbox_max_lon', 10, 7)->nullable();

            // Administrative zones.
            $table->char('country_code', 2)->nullable();
            $table->string('admin_area')->nullable();

            // Read by M26 pricing; inert in M25.
            $table->unsignedBigInteger('fee_minor')->nullable();
            $table->unsignedBigInteger('min_order_minor')->nullable();

            // A restricted zone is one the platform will not serve — the
            // inverse of a service area, and the reason zones need ordering.
            $table->boolean('is_restricted')->default(false);
            $table->boolean('is_active')->default(true)->index();

            // Lowest wins, so a specific exclusion can sit inside a broad
            // service area.
            $table->unsignedSmallInteger('priority')->default(100);

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'is_active']);
            $table->index(['bbox_min_lat', 'bbox_max_lat']);
            $table->index(['country_code', 'admin_area']);
        });

        $this->addChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_delivery_zones');
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE geo_delivery_zones
            ADD CONSTRAINT geo_delivery_zones_radius_positive
            CHECK (radius_metres IS NULL OR radius_metres > 0)
        SQL);

        // A radius zone with no centre, or a polygon zone with no polygon,
        // matches nothing and fails silently — which is worse than not existing.
        DB::statement(<<<'SQL'
            ALTER TABLE geo_delivery_zones
            ADD CONSTRAINT geo_delivery_zones_shape_present
            CHECK (
                (zone_type = 'radius' AND centre_latitude IS NOT NULL AND centre_longitude IS NOT NULL AND radius_metres IS NOT NULL)
                OR (zone_type = 'polygon' AND polygon IS NOT NULL)
                OR (zone_type = 'administrative' AND country_code IS NOT NULL)
            )
        SQL);
    }
};
