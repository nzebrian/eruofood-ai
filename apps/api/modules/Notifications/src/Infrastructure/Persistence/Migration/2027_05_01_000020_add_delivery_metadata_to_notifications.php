<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery provenance on each notification (M24).
 *
 * Three additions, each answering a question the engine previously could not:
 *
 * `provider_message_id` — "the customer says it never arrived." Without the
 * provider's own handle, the platform's claim that it sent something cannot be
 * checked against the provider's record of receiving it.
 *
 * `correlation_id` — ties a notification back to the request or verification
 * case that caused it, so a support thread can be followed end to end across
 * contexts rather than guessed at from timestamps.
 *
 * `notification_class` — transactional, security or marketing. Stored rather
 * than derived on read, because a category's classification could change and the
 * record of what a message *was when it was sent* is what a consent audit needs.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('notifications_notifications', function (Blueprint $table): void {
            $table->string('provider_message_id')->nullable()->index();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->string('notification_class', 16)->default('transactional')->index();

            // Whether a failed delivery is worth attempting again. Held on the
            // row rather than in memory: the retry loop reads notifications back
            // out of the database, so a permanence that only existed in the
            // process that discovered it would be forgotten by the one that
            // acts on it — and a dead address would be retried forever.
            $table->boolean('retryable')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('notifications_notifications', function (Blueprint $table): void {
            $table->dropIndex(['provider_message_id']);
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['notification_class']);
            $table->dropColumn(['provider_message_id', 'correlation_id', 'notification_class', 'retryable']);
        });
    }
};
