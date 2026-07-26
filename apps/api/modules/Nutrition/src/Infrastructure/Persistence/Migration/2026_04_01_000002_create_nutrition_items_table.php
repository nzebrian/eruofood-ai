<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category')->index();
            $table->string('serving_label');
            $table->decimal('serving_grams', 8, 2);
            $table->jsonb('nutrition'); // full NutritionFacts panel per serving
            $table->uuid('food_id')->nullable(); // optional soft ref to catalog_foods
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_items');
    }
};
