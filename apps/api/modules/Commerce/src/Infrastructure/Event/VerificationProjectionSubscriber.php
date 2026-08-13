<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Event;

use EruoFood\Verification\Domain\Event\SubjectExpired;
use EruoFood\Verification\Domain\Event\SubjectRejected;
use EruoFood\Verification\Domain\Event\SubjectVerified;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Keeps grocery KYB eligibility in step with Verification.
 *
 * Verification owns the decision; this listener maintains Commerce's own copy of
 * it so the eligibility check on the hot path reads a local column instead of
 * calling across a context boundary. One-way and by event name — Commerce never
 * queries Verification's tables, and Verification knows nothing about this.
 *
 * Expiry and rejection are treated identically to "never verified": a grocery
 * whose verification lapsed is no more eligible than one who never started.
 *
 * Updates are written straight to the projection column rather than through the
 * aggregate, because this is a read-model refresh, not a business decision — and
 * loading an aggregate per event would make the listener a bottleneck.
 */
final readonly class VerificationProjectionSubscriber
{
    public function register(Dispatcher $dispatcher): void
    {
        $dispatcher->listen(
            'verification.subject_verified',
            function (SubjectVerified $event): void {
                if ($this->applies($event->subjectType, $event->caseType)) {
                    $this->project($event->subjectId, 'verified', true);
                }
            },
        );

        $dispatcher->listen(
            'verification.subject_rejected',
            function (SubjectRejected $event): void {
                if ($this->applies($event->subjectType, $event->caseType)) {
                    $this->project($event->subjectId, 'rejected', false);
                }
            },
        );

        $dispatcher->listen(
            'verification.subject_expired',
            function (SubjectExpired $event): void {
                if ($this->applies($event->subjectType, $event->caseType)) {
                    $this->project($event->subjectId, 'expired', false);
                }
            },
        );
    }

    private function applies(string $subjectType, string $caseType): bool
    {
        return $subjectType === 'business' && $caseType === 'business';
    }

    private function project(string $subjectId, string $status, bool $verified): void
    {
        DB::table('commerce_stores')
            ->where('id', $subjectId)
            ->update([
                'kyb_status' => $status,
                'kyb_verified_at' => $verified ? now() : null,
            ]);
    }
}
