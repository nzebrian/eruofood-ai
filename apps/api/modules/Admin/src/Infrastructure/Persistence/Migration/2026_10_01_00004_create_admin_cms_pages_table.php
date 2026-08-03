<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('admin_cms_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type')->index();
            $table->string('slug');
            $table->string('title');
            $table->longText('body');
            $table->text('excerpt')->nullable();
            $table->jsonb('seo')->default('{}');
            $table->string('status')->default('draft')->index();
            $table->uuid('author_id')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['type', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_cms_pages');
    }
};
