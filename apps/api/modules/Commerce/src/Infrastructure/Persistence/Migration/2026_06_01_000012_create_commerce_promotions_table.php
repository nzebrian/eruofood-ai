<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('store_id')->nullable()->index();
            $table->string('name');
            $table->string('type');
            $table->integer('value');
            $table->jsonb('product_ids')->default('[]');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('flash_sale')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_promotions');
    }
};
