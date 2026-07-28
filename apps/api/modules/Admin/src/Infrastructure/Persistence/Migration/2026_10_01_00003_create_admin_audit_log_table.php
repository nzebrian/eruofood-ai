<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable()->index(); // null = system
            $table->string('category')->index();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable()->index();
            $table->jsonb('context')->default('{}');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_log');
    }
};
