<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('developer_api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id')->index();
            $table->string('name');
            $table->string('prefix')->unique();          // public lookup id
            $table->string('hashed_secret');             // never the plaintext
            $table->jsonb('scopes')->default('[]');
            $table->string('status')->default('active')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_api_keys');
    }
};
