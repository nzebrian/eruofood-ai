<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Event;

use EruoFood\Verification\Domain\Event\SubjectExpired;
use EruoFood\Verification\Domain\Event\SubjectRejected;
use EruoFood\Verification\Domain\Event\SubjectVerified;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Marketplace's eligibility columns in step with Verification.
 *
 * Two populations, two projections: restaurants (`marketplace_vendors.kyb_status`)
 * and riders (`marketplace_riders.kyc_status`). Verification owns the decision;
 * these columns are Marketplace's own copy so the checkout and dispatch paths
 * read a local value rather than calling across a context boundary.
 *
 * One-way and by event name — Marketplace never queries Verification's tables,
 * and Verification knows nothing about this listener.
 *
 * Rejection and expiry are treated exactly like "never verified". A rider whose
 * licence lapsed is no more dispatchable than one who never verified, and making
 * that automatic is the point: it is not something anyone has to remember to do.
 *
 * Writes go straight to the column rather than through the aggregate, because
 * this is a read-model refresh rather than a business decision.
 */
final readonly class VerificationProjectionSubscriber
{
    public function register(Dispatcher $dispatcher): void
    {
        $dispatcher->listen('verification.subject_verified', function (SubjectVerified $event): void {
            $this->apply($event->subjectType, $event->caseType, $event->subjectId, 'verified', true);
        });

        $dispatcher->listen('verification.subject_rejected', function (SubjectRejected $event): void {
            $this->apply($event->subjectType, $event->caseType, $event->subjectId, 'rejected', false);
        });

        $dispatcher->listen('verification.subject_expired', function (SubjectExpired $event): void {
            $this->apply($event->subjectType, $event->caseType, $event->subjectId, 'expired', false);
        });
    }

    private function apply(string $subjectType, string $caseType, string $subjectId, string $status, bool $verified): void
    {
        [$table, $column] = match (true) {
            $subjectType === 'business' && $caseType === 'business' => ['marketplace_vendors', 'kyb'],
            $subjectType === 'rider' && $caseType === 'identity' => ['marketplace_riders', 'kyc'],
            default => [null, null],
        };

        if ($table === null || $column === null) {
            return;
        }

        // Riders are keyed by their own id, but a verification case is opened
        // against the rider's *user* id — that is the identity being checked.
        // Match on either so the projection lands whichever the case carries.
        $query = DB::table($table);
        $query = $table === 'marketplace_riders'
            ? $query->where(fn ($q) => $q->where('id', $subjectId)->orWhere('user_id', $subjectId))
            : $query->where('id', $subjectId);

        $query->update([
            $column.'_status' => $status,
            $column.'_verified_at' => $verified ? now() : null,
        ]);
    }
}
