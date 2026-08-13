<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing consent and unsubscribe (M24).
 *
 * `marketing_opt_in` defaults to **false**. Marketing is the one class that
 * requires a positive choice, and defaulting an existing user base to "opted in"
 * because a column was added would manufacture consent nobody gave.
 *
 * `unsubscribe_token` is a per-user secret that lets an unsubscribe link work
 * from an email client with no session — the one place the platform must act on
 * a request it cannot authenticate normally. It is random and revocable, so a
 * leaked link costs a regenerated token rather than an account.
 *
 * `marketing_opt_in_at` records *when* consent was given, which is the question
 * a consent audit actually asks.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('notifications_preferences', function (Blueprint $table): void {
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('marketing_opt_in_at')->nullable();
            $table->string('unsubscribe_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('notifications_preferences', function (Blueprint $table): void {
            $table->dropColumn(['marketing_opt_in', 'marketing_opt_in_at', 'unsubscribe_token']);
        });
    }
};
