<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_purchase_eligibility', function (Blueprint $table): void {
            // sha256(user|subject) — deterministic so replayed events are idempotent.
            $table->string('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('subject_type');
            $table->string('subject_id');
            $table->timestamp('created_at');

            $table->index(['user_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_purchase_eligibility');
    }
};
