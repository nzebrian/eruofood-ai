<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('verification_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Soft reference into the owning context — Verification never joins
            // to Identity, Marketplace or Commerce tables.
            $table->string('subject_type', 16);
            $table->uuid('subject_id');

            $table->string('case_type', 16);
            $table->char('country_code', 2);
            $table->string('requested_level', 16);
            $table->string('status', 32)->default('not_started');

            $table->string('provider', 16)->nullable();
            $table->string('provider_reference')->nullable();

            $table->string('decision_reason_code', 48)->nullable();
            $table->text('review_note')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Optimistic concurrency, matching the M23 wallet pattern: a webhook
            // and a reviewer decision can land together, and the loser must be
            // rejected rather than silently overwrite the winner.
            $table->unsignedBigInteger('version')->default(0);

            /*
             * At most one *open* case per (subject, case type).
             *
             * Set to "type:id:caseType" while the case occupies the slot and
             * NULL once it closes. A nullable unique column gives us the
             * constraint on both PostgreSQL and SQLite, where a partial index
             * would not be portable, while still keeping full case history.
             */
            $table->string('open_key')->nullable()->unique();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'updated_at']);
            $table->index('provider_reference');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_cases');
    }
};
