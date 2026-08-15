<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Performance;

use EruoFood\Dispatch\Application\Port\RiderPerformanceQuery;
use EruoFood\Dispatch\Domain\Enum\OfferState;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model\AssignmentModel;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model\OfferModel;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\Rating\RatingSummaryRepository;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use Throwable;

/**
 * Rider performance, assembled from the contexts that already own each part.
 *
 * **The rating comes from Reviews.** Dispatch does not build a second rating
 * system: a parallel score computed here would drift from the one riders can
 * see in their app, so a rider would be penalised by a number nobody could show
 * them. M29 will own the full trust and performance engine; until then this
 * reads the summary Reviews already maintains.
 *
 * **Acceptance and completion come from Dispatch's own history**, because
 * Dispatch is the only context that knows what was offered and what was
 * accepted. They are exposed through the same port so scoring has one place to
 * ask, and so M29 can move any of it without touching the scoring service.
 *
 * Everything is nullable on purpose. A rider with no history is scored
 * *neutrally*, never badly — penalising a new rider for having no record would
 * make it impossible for them to build one.
 */
final readonly class ReviewsRiderPerformanceQuery implements RiderPerformanceQuery
{
    /** Below this many answered offers, an acceptance rate is noise rather than signal. */
    private const MIN_OFFERS_FOR_ACCEPTANCE_RATE = 5;

    public function __construct(private RatingSummaryRepository $ratings)
    {
    }

    public function forRiders(array $riderIds): array
    {
        if ($riderIds === []) {
            return [];
        }

        $riderIds = array_values(array_unique($riderIds));

        $offerCounts = $this->offerCounts($riderIds);
        $deliveryCounts = $this->deliveryCounts($riderIds);

        $performance = [];

        foreach ($riderIds as $riderId) {
            $offers = $offerCounts[$riderId] ?? ['answered' => 0, 'accepted' => 0];

            $performance[$riderId] = [
                'rating' => $this->ratingFor($riderId),
                'completion_rate' => null,
                'acceptance_rate' => $offers['answered'] >= self::MIN_OFFERS_FOR_ACCEPTANCE_RATE
                    ? $offers['accepted'] / $offers['answered']
                    // Not zero. A rider who has answered two offers has not
                    // demonstrated a bad acceptance rate, and treating them as
                    // though they had would stop them getting a third.
                    : null,
                'deliveries' => $deliveryCounts[$riderId] ?? 0,
            ];
        }

        return $performance;
    }

    /**
     * The rider's public star rating, or null if Reviews has none.
     *
     * Read defensively: a rating is an input to a score, not a safety control,
     * and a Reviews outage must degrade ranking rather than stop dispatch.
     */
    private function ratingFor(string $riderId): ?float
    {
        try {
            $summary = $this->ratings->findBySubject(new Subject(SubjectType::Rider, $riderId));
        } catch (Throwable) {
            return null;
        }

        if ($summary === null || $summary->count === 0) {
            return null;
        }

        return $summary->average;
    }

    /**
     * @param list<string> $riderIds
     * @return array<string, array{answered: int, accepted: int}>
     */
    private function offerCounts(array $riderIds): array
    {
        $rows = OfferModel::query()
            ->whereIn('rider_id', $riderIds)
            // Cancelled offers were withdrawn by the platform and say nothing
            // about the rider — counting them would penalise a rider for losing
            // a race they never entered.
            ->whereIn('state', [
                OfferState::Accepted->value,
                OfferState::Declined->value,
                OfferState::Expired->value,
            ])
            ->selectRaw('rider_id, state, COUNT(*) as total')
            ->groupBy('rider_id', 'state')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $riderId = (string) $row->rider_id;
            $total = (int) $row->getAttribute('total');

            $counts[$riderId] ??= ['answered' => 0, 'accepted' => 0];
            $counts[$riderId]['answered'] += $total;

            if ($row->state === OfferState::Accepted->value) {
                $counts[$riderId]['accepted'] += $total;
            }
        }

        return $counts;
    }

    /**
     * @param list<string> $riderIds
     * @return array<string, int>
     */
    private function deliveryCounts(array $riderIds): array
    {
        $rows = AssignmentModel::query()
            ->whereIn('rider_id', $riderIds)
            ->where('state', 'delivered')
            ->selectRaw('rider_id, COUNT(*) as total')
            ->groupBy('rider_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->rider_id] = (int) $row->getAttribute('total');
        }

        return $counts;
    }
}
