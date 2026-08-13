<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who to talk to about a case (M24).
 *
 * A rider or customer case is about a person, and the subject *is* the contact.
 * A business case is about a company: the subject is a vendor or store id, and
 * nobody can be emailed at it. The person to reach is the account that owns the
 * business.
 *
 * Resolved once when the case is opened rather than at every notification. The
 * ownership question belongs to Marketplace and Commerce, and asking them on
 * each send would put a cross-context lookup on a path that runs whenever
 * anything happens to a case.
 *
 * Nullable because a case may legitimately have no reachable contact — an
 * orphaned business record, an account closed mid-verification — and that should
 * mean "no email", not a failed verification.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('verification_cases', function (Blueprint $table): void {
            $table->uuid('contact_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('verification_cases', function (Blueprint $table): void {
            $table->dropIndex(['contact_user_id']);
            $table->dropColumn('contact_user_id');
        });
    }
};
