<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('catalog_recipe_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id')->index();
            $table->integer('version');
            $table->jsonb('snapshot');
            $table->timestamp('created_at')->nullable();

            $table->unique(['recipe_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_recipe_versions');
    }
};
