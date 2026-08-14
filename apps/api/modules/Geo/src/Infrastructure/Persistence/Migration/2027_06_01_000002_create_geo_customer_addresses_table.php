<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's address book.
 *
 * Before M25 there was none: addresses were embedded as jsonb on each order, so
 * a customer retyped theirs every time and the platform had no way to know two
 * orders went to the same door.
 *
 * `location_id` is a soft reference to `geo_locations` — the geographic facts
 * live there, the customer's relationship to them lives here. That split is
 * what lets two neighbours share a building's geocode while keeping their own
 * labels and delivery instructions.
 *
 * Rows are deactivated rather than deleted, because historical orders point at
 * them and an order whose address vanished is an order nobody can investigate.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('geo_customer_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();      // soft ref to identity_users
            $table->uuid('location_id')->index();  // soft ref to geo_locations

            $table->string('label', 16)->default('other');   // home | work | other
            $table->string('custom_name')->nullable();       // when label = other

            // Free text from the customer: "blue gate, ask for Musa". Genuinely
            // useful to a rider, and genuinely personal — it is never published
            // and never travels on an event.
            $table->text('delivery_instructions')->nullable();
            $table->string('contact_phone', 32)->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // The list view: a customer's active addresses, default first.
            $table->index(['user_id', 'is_active', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_customer_addresses');
    }
};
