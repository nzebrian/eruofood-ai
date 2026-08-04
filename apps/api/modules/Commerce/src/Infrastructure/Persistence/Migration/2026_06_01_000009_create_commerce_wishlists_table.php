<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('commerce_wishlists', function (Blueprint $table): void {
            $table->uuid('user_id')->primary(); // one wishlist per user
            $table->jsonb('product_ids')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_wishlists');
    }
};
