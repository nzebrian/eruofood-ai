<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_preferences', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->jsonb('channels_by_category')->default('{}');
            $table->jsonb('quiet_hours')->default('{}');
            $table->string('language', 8)->default('en');
            $table->unsignedInteger('max_per_day')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_preferences');
    }
};
