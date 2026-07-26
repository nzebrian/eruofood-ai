<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_riders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique(); // soft ref to identity_users
            $table->string('name');
            $table->string('phone');
            $table->string('vehicle_type');
            $table->string('status')->default('offline')->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_riders');
    }
};
