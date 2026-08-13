<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local eligibility projection for restaurant KYB.
 *
 * Verification owns the decision; this column is Marketplace's own copy of it,
 * maintained by subscribing to verification events. Keeping it here means
 * `Vendor::canTrade()` answers from its own row instead of calling across a
 * context boundary on the checkout path.
 *
 * Defaults to `not_started` so deploying this migration changes nothing on its
 * own — enforcement is a separate, deliberate switch.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_vendors', function (Blueprint $table): void {
            $table->string('kyb_status', 32)->default('not_started');
            $table->timestamp('kyb_verified_at')->nullable();
            $table->index('kyb_status');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_vendors', function (Blueprint $table): void {
            $table->dropIndex(['kyb_status']);
            $table->dropColumn(['kyb_status', 'kyb_verified_at']);
        });
    }
};
