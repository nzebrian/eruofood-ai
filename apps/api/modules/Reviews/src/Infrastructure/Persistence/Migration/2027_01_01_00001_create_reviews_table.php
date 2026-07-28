<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type')->index();
            $table->string('subject_id')->index();
            $table->uuid('author_id')->index();
            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->jsonb('photos')->default('[]');
            $table->boolean('verified_purchase')->default(false)->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('helpful_yes')->default(0);
            $table->unsignedInteger('helpful_no')->default(0);
            $table->jsonb('owner_response')->nullable();
            $table->uuid('moderated_by')->nullable();
            $table->string('moderation_reason')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->index();

            // One review per (subject, author).
            $table->unique(['subject_type', 'subject_id', 'author_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
