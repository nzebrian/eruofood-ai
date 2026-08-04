<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('nutrition_health_profiles', function (Blueprint $table): void {
            $table->uuid('user_id')->primary(); // one profile per user (soft ref to identity_users)
            $table->decimal('weight_kg', 6, 2);
            $table->decimal('height_cm', 6, 2);
            $table->unsignedSmallInteger('age');
            $table->string('gender');
            $table->string('activity_level');
            $table->string('goal');
            $table->jsonb('dietary_preferences')->default('[]');
            $table->jsonb('allergies')->default('[]');
            $table->jsonb('medical_restrictions')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_health_profiles');
    }
};
