<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('feature')->index();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->text('system_template');
            $table->text('user_template');
            $table->string('model')->nullable(); // optional provider model pin
            $table->jsonb('variables')->default('[]');
            $table->boolean('active')->default(false);
            $table->timestamps();

            // A feature can only have one row per version number.
            $table->unique(['feature', 'version']);
            $table->index(['feature', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_templates');
    }
};
