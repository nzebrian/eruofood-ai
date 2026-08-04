<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('commerce_carts', function (Blueprint $table): void {
            $table->uuid('user_id')->primary(); // one cart per user
            $table->string('coupon_code')->nullable();
            $table->jsonb('items')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_carts');
    }
};
