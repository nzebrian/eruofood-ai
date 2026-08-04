<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The OAuth2 authorization-server tables. Tokens and codes store only hashes of
 * their secret material (never the plaintext), indexed by that hash for O(1)
 * lookup. Scopes are stored as JSON — the same scope currency as API keys.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id')->index();
            $table->uuid('developer_id')->index();
            $table->string('name');
            $table->string('hashed_secret')->nullable(); // null = public client (PKCE only)
            $table->boolean('confidential')->default(true);
            $table->json('grants');
            $table->json('redirect_uris');
            $table->json('allowed_scopes');
            $table->timestamp('created_at');
        });

        Schema::create('oauth_authorization_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('hashed_code')->unique();
            $table->uuid('client_id')->index();
            $table->uuid('subject_user_id')->index();
            $table->string('redirect_uri');
            $table->json('scopes');
            $table->string('code_challenge');
            $table->string('code_challenge_method', 16);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('hashed_token')->unique();
            $table->uuid('client_id')->index();
            $table->uuid('application_id')->index();
            $table->uuid('developer_id')->index();
            $table->uuid('subject_user_id')->nullable()->index();
            $table->json('scopes');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('hashed_token')->unique();
            $table->uuid('access_token_id')->index();
            $table->uuid('client_id')->index();
            $table->uuid('application_id')->index();
            $table->uuid('developer_id')->index();
            $table->uuid('subject_user_id')->nullable()->index();
            $table->json('scopes');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_authorization_codes');
        Schema::dropIfExists('oauth_clients');
    }
};
