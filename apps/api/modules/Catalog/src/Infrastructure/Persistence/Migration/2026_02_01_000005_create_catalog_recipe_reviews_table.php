<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('catalog_recipe_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id')->index();
            $table->uuid('user_id')->index();
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->text('comment')->nullable();
            $table->timestamps();

            // One review per user per recipe.
            $table->unique(['recipe_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_recipe_reviews');
    }
};
