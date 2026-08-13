<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local eligibility projection for rider KYC.
 *
 * A rider's identity verification decides whether they may be dispatched, so the
 * status is projected onto the rider row and read at assignment time.
 *
 * Defaults to `not_started`; existing riders keep working until enforcement is
 * switched on deliberately.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_riders', function (Blueprint $table): void {
            $table->string('kyc_status', 32)->default('not_started');
            $table->timestamp('kyc_verified_at')->nullable();
            $table->index('kyc_status');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_riders', function (Blueprint $table): void {
            $table->dropIndex(['kyc_status']);
            $table->dropColumn(['kyc_status', 'kyc_verified_at']);
        });
    }
};
