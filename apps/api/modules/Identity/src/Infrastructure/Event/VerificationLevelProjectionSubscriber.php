<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Event;

use EruoFood\Verification\Domain\Event\PhoneVerified;
use EruoFood\Verification\Domain\Event\SubjectExpired;
use EruoFood\Verification\Domain\Event\SubjectRejected;
use EruoFood\Verification\Domain\Event\SubjectVerified;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `identity_users.verification_level` in step with Verification.
 *
 * Progressive verification means an account's assurance is a ladder — `basic`
 * on registration, `phone` once a number is confirmed, `identity` once a
 * document has passed — and step-up checks read this column rather than
 * querying another context on every sensitive request.
 *
 * The ladder only ever moves up here, with one exception: a rejected or expired
 * *identity* case drops the account back to whatever its phone confirmation
 * earns it, rather than to `basic`. Losing a document check should not also
 * erase a number the customer genuinely confirmed.
 *
 * One-way and by event name — Identity never queries Verification's tables, and
 * Verification knows nothing about this listener.
 */
final readonly class VerificationLevelProjectionSubscriber
{
    public function register(Dispatcher $dispatcher): void
    {
        $dispatcher->listen('verification.phone_verified', function (PhoneVerified $event): void {
            $this->raiseToPhone($event->userId);
        });

        $dispatcher->listen('verification.subject_verified', function (SubjectVerified $event): void {
            if ($this->isCustomerIdentity($event->subjectType, $event->caseType)) {
                $this->setLevel($event->subjectId, 'identity');
            }
        });

        $dispatcher->listen('verification.subject_rejected', function (SubjectRejected $event): void {
            if ($this->isCustomerIdentity($event->subjectType, $event->caseType)) {
                $this->demote($event->subjectId);
            }
        });

        $dispatcher->listen('verification.subject_expired', function (SubjectExpired $event): void {
            if ($this->isCustomerIdentity($event->subjectType, $event->caseType)) {
                $this->demote($event->subjectId);
            }
        });
    }

    private function isCustomerIdentity(string $subjectType, string $caseType): bool
    {
        return $subjectType === 'customer' && $caseType === 'identity';
    }

    private function raiseToPhone(string $userId): void
    {
        DB::table('identity_users')
            ->where('id', $userId)
            // Never downgrade somebody who already holds the stronger level.
            ->where('verification_level', '!=', 'identity')
            ->update(['verification_level' => 'phone', 'phone_verified_at' => now()]);
    }

    private function setLevel(string $userId, string $level): void
    {
        DB::table('identity_users')->where('id', $userId)->update(['verification_level' => $level]);
    }

    private function demote(string $userId): void
    {
        $hasPhone = DB::table('identity_users')
            ->where('id', $userId)
            ->whereNotNull('phone_verified_at')
            ->exists();

        $this->setLevel($userId, $hasPhone ? 'phone' : 'basic');
    }
}
