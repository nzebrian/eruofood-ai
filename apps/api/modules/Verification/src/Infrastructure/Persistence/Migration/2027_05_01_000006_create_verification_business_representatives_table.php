<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('verification_business_representatives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_profile_id')->index();
            $table->uuid('user_id')->index();

            $table->string('full_name');
            $table->string('role', 64);
            $table->boolean('is_primary')->default(false);

            // Points at a normal identity case: a company record says nothing
            // about who is operating the account, so the representative carries
            // their own verification rather than duplicating identity fields.
            $table->uuid('identity_case_id')->nullable()->index();

            // Populated only where law requires beneficial-owner disclosure.
            $table->decimal('ownership_percentage', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['business_profile_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_business_representatives');
    }
};
