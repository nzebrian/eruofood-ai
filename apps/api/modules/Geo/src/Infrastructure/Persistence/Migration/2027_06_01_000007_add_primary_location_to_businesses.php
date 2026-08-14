<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point merchants at their canonical location.
 *
 * Purely additive and nullable. The existing `address` jsonb columns stay
 * exactly as they are, so every current read path keeps working and nothing has
 * to be migrated before it is ready — the same additive approach M24 used for
 * its projection columns.
 *
 * `verification_business_profiles.location_id` finally wires up the latitude and
 * longitude columns M24 created and never populated: on KYB approval the
 * registered address can now be geocoded. The registered address itself stays
 * private; only the trading address is ever published, because the two are
 * frequently different and one of them is often somebody's home.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_vendors', function (Blueprint $table): void {
            $table->uuid('primary_location_id')->nullable()->index();
        });

        Schema::table('commerce_stores', function (Blueprint $table): void {
            $table->uuid('primary_location_id')->nullable()->index();
        });

        Schema::table('verification_business_profiles', function (Blueprint $table): void {
            $table->uuid('location_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_vendors', function (Blueprint $table): void {
            $table->dropIndex(['primary_location_id']);
            $table->dropColumn('primary_location_id');
        });

        Schema::table('commerce_stores', function (Blueprint $table): void {
            $table->dropIndex(['primary_location_id']);
            $table->dropColumn('primary_location_id');
        });

        Schema::table('verification_business_profiles', function (Blueprint $table): void {
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
