<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_vendors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_user_id')->index(); // soft ref to identity_users
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->index();
            $table->string('status')->default('pending')->index();
            $table->string('category')->index();
            $table->text('description')->nullable();
            $table->jsonb('contact')->default('{}');
            $table->jsonb('address')->default('{}');
            $table->jsonb('branches')->default('[]');
            $table->jsonb('business_hours')->default('{}');
            $table->jsonb('delivery_zones')->default('[]');
            $table->jsonb('images')->default('[]');
            $table->boolean('featured')->default(false);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_vendors');
    }
};
