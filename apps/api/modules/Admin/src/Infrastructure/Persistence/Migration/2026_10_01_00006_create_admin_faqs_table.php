<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_faqs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('question');
            $table->longText('answer');
            $table->string('category')->index();
            $table->integer('sort_order')->default(0);
            $table->boolean('published')->default(true)->index();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_faqs');
    }
};
