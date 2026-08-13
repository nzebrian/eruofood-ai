<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local eligibility projection for grocery KYB.
 *
 * The mirror of the Marketplace column, kept separate because the catalogues are
 * separate — M24 does not consolidate them.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('commerce_stores', function (Blueprint $table): void {
            $table->string('kyb_status', 32)->default('not_started');
            $table->timestamp('kyb_verified_at')->nullable();
            $table->index('kyb_status');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_stores', function (Blueprint $table): void {
            $table->dropIndex(['kyb_status']);
            $table->dropColumn(['kyb_status', 'kyb_verified_at']);
        });
    }
};
