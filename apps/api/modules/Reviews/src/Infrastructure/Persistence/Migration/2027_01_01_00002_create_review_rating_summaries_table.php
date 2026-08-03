<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('review_rating_summaries', function (Blueprint $table): void {
            // Deterministic key "type:id" so a subject has exactly one summary.
            $table->string('id')->primary();
            $table->string('subject_type')->index();
            $table->string('subject_id');
            $table->unsignedInteger('count')->default(0);
            $table->decimal('average', 4, 3)->default(0);
            $table->jsonb('distribution')->default('{}');
            $table->unsignedInteger('verified_count')->default(0);
            $table->timestamp('updated_at')->index();

            $table->unique(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_rating_summaries');
    }
};
