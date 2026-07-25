<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('password')->nullable(); // null for social-only accounts
            $table->string('status')->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('avatar_path')->nullable();
            $table->jsonb('roles')->default(json_encode(['user']));
            $table->jsonb('preferences')->default(json_encode([]));
            $table->text('two_factor_secret')->nullable();          // encrypted
            $table->text('two_factor_recovery_codes')->nullable();  // encrypted
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_users');
    }
};
