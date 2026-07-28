<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_impersonations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('admin_user_id')->index();
            $table->uuid('target_user_id')->index();
            $table->string('reason');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_impersonations');
    }
};
