<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_menu_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vendor_id')->index();
            $table->uuid('category_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('description_ai_generated')->default(false);
            $table->unsignedBigInteger('base_price_minor');
            $table->string('currency', 3)->default('NGN');
            $table->jsonb('variants')->default('[]');
            $table->boolean('available')->default(true);
            $table->jsonb('images')->default('[]');
            $table->jsonb('tags')->default('[]');
            $table->boolean('featured')->default(false);
            $table->jsonb('promotion')->nullable();
            $table->boolean('track_inventory')->default(false);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('calories')->nullable();
            $table->uuid('nutrition_item_id')->nullable(); // soft ref to nutrition_items
            $table->timestamps();

            $table->index(['vendor_id', 'available']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_menu_items');
    }
};
