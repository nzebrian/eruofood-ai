<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone confirmation challenges — one row per account.
 *
 * `code_hash` rather than the code: a database copy must not be enough to
 * complete somebody's verification. `attempts` lives on the row rather than in a
 * cache so the limit survives a cache flush, which is precisely when an attacker
 * would like it to reset.
 *
 * `user_id` is a soft reference to Identity, unique because a live challenge is
 * replaced rather than accumulated — two valid codes would double the guessing
 * surface for no benefit.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('verification_phone_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->string('phone', 32);
            $table->string('code_hash', 255);
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            // The step-up path asks "is this account's number confirmed?" on
            // sensitive requests, so that lookup gets its own index.
            $table->index(['user_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_phone_challenges');
    }
};
