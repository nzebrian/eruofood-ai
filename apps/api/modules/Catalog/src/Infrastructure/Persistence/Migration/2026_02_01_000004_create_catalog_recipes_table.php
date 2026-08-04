<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('catalog_recipes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('food_id')->index();     // soft reference to catalog_foods
            $table->uuid('author_id')->index();   // soft reference to identity_users
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->integer('prep_time_minutes')->default(0);
            $table->integer('cook_time_minutes')->default(0);
            $table->string('difficulty')->default('easy');
            $table->integer('serving_size')->default(1);
            $table->jsonb('ingredients')->default('[]');
            $table->jsonb('steps')->default('[]');
            $table->jsonb('tips')->default('[]');
            $table->jsonb('tags')->default('[]');
            $table->jsonb('related_recipe_ids')->default('[]');
            $table->string('status')->default('draft');
            $table->integer('version')->default(1);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'difficulty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_recipes');
    }
};
