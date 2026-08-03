<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('commerce_inventory_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('product_id')->index();
            $table->string('variant_sku')->nullable();
            $table->uuid('warehouse_id')->nullable()->index();
            $table->uuid('supplier_id')->nullable()->index();
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->jsonb('batches')->default('[]');
            $table->timestamps();

            $table->unique(['product_id', 'variant_sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_inventory_items');
    }
};
