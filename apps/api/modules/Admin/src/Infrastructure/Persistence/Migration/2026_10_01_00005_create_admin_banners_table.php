<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('admin_banners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('image_url');
            $table->string('link_url')->nullable();
            $table->string('placement')->index();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_banners');
    }
};
