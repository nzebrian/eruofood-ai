<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Interface\Console;

use EruoFood\Dispatch\Application\Service\OfferExpiryService;
use Illuminate\Console\Command;

/**
 * Close out offers nobody answered and searches that ran out of time.
 *
 * Scheduled every minute. Safe to run twice at once and safe to miss a run: it
 * works from stored deadlines rather than from timers, so an interrupted worker
 * costs a minute of latency rather than leaving a rider apparently holding a job
 * for ever.
 */
final class ExpireOffersCommand extends Command
{
    protected $signature = 'dispatch:expire-offers
                            {--offers=200 : Maximum offers to expire in one run}
                            {--requests=100 : Maximum timed-out searches to fail in one run}';

    protected $description = 'Expire unanswered rider offers and fail dispatch requests past their time budget.';

    public function handle(OfferExpiryService $expiry): int
    {
        $offers = $expiry->sweepOffers((int) $this->option('offers'));
        $requests = $expiry->sweepTimedOutRequests((int) $this->option('requests'));

        $this->info(sprintf(
            'Expired %d offer(s); failed %d search(es) past their time budget.',
            $offers,
            $requests,
        ));

        return self::SUCCESS;
    }
}
