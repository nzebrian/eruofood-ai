<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action')->index();
            $table->uuid('actor_id')->nullable()->index();
            $table->jsonb('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_audit_logs');
    }
};
