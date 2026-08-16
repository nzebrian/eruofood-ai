<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a session an identity, and a way to notice it has been stolen.
 *
 * A refresh-token row already *is* a session, with an IP and a user agent. Two
 * things were missing, and both matter once riders and merchants carry the app
 * on shared or lost handsets:
 *
 * - **Which device.** "Signed in on 3 devices" is not answerable from an IP
 *   that changes with every cell tower and a user-agent string shared by every
 *   phone of the same model. A client-supplied device id is not proof of
 *   anything, but it is a stable label a person can recognise in a session
 *   list — which is what makes "revoke the one I do not recognise" possible.
 *
 * - **Whether a rotated token was replayed.** Rotation already replaces the
 *   secret on every refresh, so an old token stops working. But *silently*: the
 *   attempt was rejected and nothing recorded it. A replayed old token is the
 *   signature of a stolen one — the thief used it, the real device rotated past
 *   it, or the reverse — and the standard response is to end the whole session
 *   family rather than let the race continue.
 *
 * All columns are nullable and additive. Existing sessions keep working with no
 * device information and no reuse history, which is the correct reading of a
 * session that predates this.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('identity_refresh_tokens', function (Blueprint $table): void {
            // Client-supplied and therefore untrusted: a label, never an
            // authorisation input. Nothing grants access on the strength of it.
            $table->string('device_id', 128)->nullable()->after('user_id');
            $table->string('device_name', 128)->nullable()->after('device_id');
            $table->string('platform', 32)->nullable()->after('device_name');

            // Touched on ordinary authenticated activity, not only on rotation.
            // `last_used_at` moves when a refresh happens, which can be hours
            // apart; "last active" is what a person recognises in a session list.
            $table->timestamp('last_activity_at')->nullable()->after('last_used_at');

            // Set when a token that had already been rotated away is presented.
            $table->timestamp('reuse_detected_at')->nullable()->after('revoked_at');

            // Finding live sessions for a device, and finding compromised ones.
            $table->index(['user_id', 'device_id'], 'identity_refresh_user_device_idx');
            $table->index('reuse_detected_at', 'identity_refresh_reuse_idx');
        });
    }

    public function down(): void
    {
        Schema::table('identity_refresh_tokens', function (Blueprint $table): void {
            $table->dropIndex('identity_refresh_user_device_idx');
            $table->dropIndex('identity_refresh_reuse_idx');
            $table->dropColumn([
                'device_id',
                'device_name',
                'platform',
                'last_activity_at',
                'reuse_detected_at',
            ]);
        });
    }
};
