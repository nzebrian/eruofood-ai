<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('support_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('body');
            $table->text('excerpt')->nullable();
            $table->string('category')->index();
            $table->string('status')->default('draft')->index();
            $table->integer('version')->default(1);
            $table->jsonb('tags')->default('[]');
            $table->integer('helpful_yes')->default(0);
            $table->integer('helpful_no')->default(0);
            $table->uuid('author_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_articles');
    }
};
