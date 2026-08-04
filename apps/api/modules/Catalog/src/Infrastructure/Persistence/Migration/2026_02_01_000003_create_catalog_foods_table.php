<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('catalog_foods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->uuid('category_id')->index();   // soft reference (no cross-context FK)
            $table->string('region');
            $table->jsonb('states')->default('[]');
            $table->jsonb('local_names')->default('[]');
            $table->jsonb('nutrition')->nullable();
            $table->jsonb('images')->default('[]');
            $table->string('video_url')->nullable(); // video: architecture-ready
            $table->jsonb('tags')->default('[]');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_foods');
    }
};
