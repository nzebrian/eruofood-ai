<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('commerce_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('store_id')->index();
            $table->uuid('category_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('kind')->index();
            $table->string('department')->nullable()->index();
            $table->text('description')->nullable();
            $table->boolean('description_ai_generated')->default(false);
            $table->unsignedBigInteger('base_price_minor')->default(0);
            $table->jsonb('variants')->default('[]');
            $table->jsonb('images')->default('[]');
            $table->jsonb('tags')->default('[]');
            $table->string('status')->default('draft')->index();
            $table->boolean('featured')->default(false)->index();
            $table->string('barcode')->nullable()->index();
            $table->string('brand')->nullable();
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_products');
    }
};
