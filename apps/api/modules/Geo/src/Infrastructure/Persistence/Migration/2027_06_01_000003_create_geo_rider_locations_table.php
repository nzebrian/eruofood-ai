<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each rider currently is.
 *
 * `marketplace_riders` already held latitude and longitude, but with no
 * timestamp — so there was no way to tell a rider who moved five seconds ago
 * from one who last reported five days ago. Dispatch cannot be built on that,
 * and neither can an honest "your rider is nearby".
 *
 * **One row per rider, not a trail.** M25 needs the current position and
 * nothing more. A location history is what live tracking (M27) will need, and
 * collecting one now would mean accumulating a movement record for every rider
 * with no use for it — the clearest possible case of over-collection. When
 * history arrives it should arrive with a retention policy attached.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('geo_rider_locations', function (Blueprint $table): void {
            $table->uuid('rider_id')->primary();   // soft ref to marketplace_riders
            $table->uuid('user_id')->index();      // the account authorised to write it

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            // What the device reported about its own certainty. A 2 km accuracy
            // radius is not a position, and dispatch should be able to tell.
            $table->decimal('accuracy_metres', 8, 2)->nullable();
            $table->decimal('heading_degrees', 6, 2)->nullable();
            $table->decimal('speed_mps', 8, 2)->nullable();

            $table->string('source', 16)->default('device');

            // The field whose absence made the old columns unusable.
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });

        $this->addChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_rider_locations');
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE geo_rider_locations
            ADD CONSTRAINT geo_rider_locations_latitude_range
            CHECK (latitude >= -90 AND latitude <= 90)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geo_rider_locations
            ADD CONSTRAINT geo_rider_locations_longitude_range
            CHECK (longitude >= -180 AND longitude <= 180)
        SQL);

        // A negative accuracy radius is meaningless and would defeat any
        // staleness or quality check built on it.
        DB::statement(<<<'SQL'
            ALTER TABLE geo_rider_locations
            ADD CONSTRAINT geo_rider_locations_accuracy_positive
            CHECK (accuracy_metres IS NULL OR accuracy_metres >= 0)
        SQL);
    }
};
