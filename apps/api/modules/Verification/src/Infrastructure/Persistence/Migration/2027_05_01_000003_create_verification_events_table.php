<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('verification_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();

            $table->string('from_status', 32);
            $table->string('to_status', 32);

            // system | provider | admin | subject
            $table->string('actor_type', 16);
            // A string rather than a uuid: an actor is a person's id *or* the
            // name of the component that acted — `reconciliation`, the expiry
            // sweep, a provider. Typing this as a uuid made every system-driven
            // transition fail on PostgreSQL while passing on SQLite.
            $table->string('actor_id', 64)->nullable()->index();

            $table->string('reason_code', 48)->nullable();
            $table->text('note')->nullable();

            $table->timestamp('occurred_at')->index();

            // No updated_at: the row is written once and never touched again.
            $table->index(['case_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_events');
    }
};
