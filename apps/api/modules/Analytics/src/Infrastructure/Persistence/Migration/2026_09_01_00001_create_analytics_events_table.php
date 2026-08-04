<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->index();          // event name (soft ref)
            $table->string('category')->index();
            $table->uuid('actor_id')->nullable();
            $table->bigInteger('value')->default(0);
            $table->jsonb('dimensions')->default('{}');
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
