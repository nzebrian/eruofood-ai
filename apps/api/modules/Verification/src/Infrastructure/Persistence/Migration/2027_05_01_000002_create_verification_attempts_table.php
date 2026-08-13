<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('verification_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();
            $table->string('provider', 16);

            // Unique because a provider session identifies exactly one attempt.
            // This is also what makes resolving an inbound webhook to a case
            // unambiguous.
            $table->string('provider_reference')->unique();

            $table->string('status', 32);

            // The provider's own status string, kept verbatim. When a provider
            // adds a value we have not mapped, this is what lets a human explain
            // the case instead of guessing.
            $table->string('raw_provider_status')->nullable();
            $table->string('reason_code', 48)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('decided_at')->nullable();

            $table->index(['case_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_attempts');
    }
};
