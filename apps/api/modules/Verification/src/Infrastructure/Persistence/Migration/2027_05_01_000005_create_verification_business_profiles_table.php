<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('verification_business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // restaurant (Marketplace) or grocery (Commerce). The catalogues stay
            // separate; only the KYB question is shared.
            $table->string('business_kind', 16);
            $table->uuid('business_id');

            $table->char('country_code', 2);
            $table->string('registered_name');
            $table->string('trading_name');
            $table->string('business_type', 64);

            // Encrypted at rest — a registration number identifies a real legal
            // entity and its directors.
            $table->text('registration_number');

            // CAC in Nigeria; whatever the market's authority is elsewhere.
            $table->string('registration_authority', 32);

            $table->jsonb('address')->default('{}');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->uuid('identity_case_id')->nullable()->index();

            // Hook for M27: bank details get their own verification case.
            $table->uuid('payout_account_case_id')->nullable();

            $table->timestamps();

            $table->unique(['business_kind', 'business_id']);
            $table->index(['country_code', 'registration_authority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_business_profiles');
    }
};
