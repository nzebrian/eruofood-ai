<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progressive verification state on the account.
 *
 * `verification_level` is Identity's projection of how strongly the account is
 * established: `basic` after registration, `phone` once a number is confirmed,
 * `identity` once a document has been verified. Step-up checks read it, so it
 * lives next to the user rather than behind a cross-context call on every
 * sensitive request.
 *
 * Every existing account starts at `basic`, which is exactly what ordinary
 * registration already produced — nobody is downgraded by this migration.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('identity_users', function (Blueprint $table): void {
            $table->string('verification_level', 16)->default('basic');
            $table->timestamp('phone_verified_at')->nullable();
            $table->index('verification_level');
        });
    }

    public function down(): void
    {
        Schema::table('identity_users', function (Blueprint $table): void {
            $table->dropIndex(['verification_level']);
            $table->dropColumn(['verification_level', 'phone_verified_at']);
        });
    }
};
