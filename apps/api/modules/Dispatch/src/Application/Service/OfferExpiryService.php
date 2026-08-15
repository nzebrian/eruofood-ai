<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use EruoFood\Dispatch\Domain\Enum\DispatchFailureReason;
use EruoFood\Dispatch\Domain\Event\DispatchFailed;
use EruoFood\Dispatch\Domain\Event\OfferExpired;
use EruoFood\Dispatch\Domain\Offer\OfferRepository;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use EruoFood\Shared\Domain\TransactionManager;

/**
 * Close out offers nobody answered, and requests that ran out of time.
 *
 * ## Why this is a sweep and not a delayed job
 *
 * A per-offer timer would be simpler and would lose offers whenever a worker
 * restarted — leaving a rider apparently holding a job forever and a customer
 * waiting on a timer that no longer exists. A sweep over stored deadlines
 * recovers from any interruption, because the state that matters is in the
 * database rather than in a queue.
 *
 * ## Losing a race here is a success
 *
 * A rider tapping Accept at the same instant this runs will take the row's lock
 * first or second. If they win, this finds a terminal offer and skips it; if
 * this wins, their Accept is refused as expired. Either way exactly one thing
 * happens, and a {@see ConcurrencyConflict} while sweeping means the rider
 * answered — which is the outcome everybody wanted, so it is swallowed rather
 * than logged as a failure.
 */
final readonly class OfferExpiryService
{
    public function __construct(
        private OfferRepository $offers,
        private DispatchRequestRepository $requests,
        private TransactionManager $transactions,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    /**
     * Expire unanswered offers whose TTL has passed.
     *
     * @return int how many were expired
     */
    public function sweepOffers(int $limit = 200): int
    {
        $now = $this->clock->now();
        $expired = 0;

        foreach ($this->offers->expiredUnanswered($now, $limit) as $stale) {
            try {
                $this->transactions->atomic(function () use ($stale, $now): void {
                    $offer = $this->offers->lockForUpdate($stale->id());

                    // Answered between the scan and the lock. Nothing to do,
                    // and nothing has gone wrong.
                    if ($offer === null || ! $offer->state()->isAnswerable()) {
                        return;
                    }

                    $offer->expire($now);
                    $this->offers->save($offer);

                    $this->events->publish(new OfferExpired(
                        $offer->id(),
                        $offer->requestId(),
                        $offer->riderId(),
                        $now,
                    ));
                });

                $expired++;
            } catch (ConcurrencyConflict) {
                // The rider got there first. That is the point of the lock, not
                // an error to report.
                continue;
            }
        }

        return $expired;
    }

    /**
     * Fail requests whose time budget has run out.
     *
     * The honest end of a search: after ten minutes a customer deserves to be
     * told nobody could be found, not to keep waiting while the engine cycles.
     * Operations owns it from here, which is why the event carries the reason
     * and the attempt count rather than just the id.
     *
     * @return int how many were failed
     */
    public function sweepTimedOutRequests(int $limit = 100): int
    {
        $now = $this->clock->now();
        $failed = 0;

        foreach ($this->requests->timedOut($now, $limit) as $stale) {
            try {
                $this->transactions->atomic(function () use ($stale, $now, &$failed): void {
                    $request = $this->requests->lockForUpdate($stale->id());

                    // A rider accepted between the scan and the lock. The
                    // customer has a rider; there is nothing to fail.
                    if ($request === null || $request->state()->isTerminal()) {
                        return;
                    }

                    $request->fail(DispatchFailureReason::TimeBudgetExhausted, $now);
                    $this->requests->save($request);

                    // Withdraw anything still on a rider's screen for a request
                    // that has been given up on.
                    foreach ($this->offers->liveForRequest($request->id()) as $offer) {
                        $offer->cancel($now);
                        $this->offers->save($offer);
                    }

                    $this->events->publish(new DispatchFailed(
                        $request->id(),
                        $request->deliveryId(),
                        DispatchFailureReason::TimeBudgetExhausted->value,
                        null,
                        $request->attemptCount(),
                        $now,
                    ));

                    $failed++;
                });
            } catch (ConcurrencyConflict) {
                continue;
            }
        }

        return $failed;
    }
}
