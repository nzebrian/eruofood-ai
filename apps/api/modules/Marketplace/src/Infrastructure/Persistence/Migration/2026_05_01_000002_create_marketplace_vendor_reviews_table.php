<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_vendor_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vendor_id')->index();
            $table->uuid('user_id')->index();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_vendor_reviews');
    }
};
