<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_carts', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->uuid('vendor_id')->nullable();
            $table->jsonb('items')->default('[]');
            $table->string('currency', 3)->default('NGN');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_carts');
    }
};
