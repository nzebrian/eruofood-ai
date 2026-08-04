<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Application\Service;

use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\Rating\RatingSummary;
use EruoFood\Reviews\Domain\Rating\RatingSummaryRepository;
use EruoFood\Reviews\Domain\Review\ReviewStatsRepository;

/**
 * Read-only analytics over the review corpus for the admin workspace: the
 * moderation funnel (pending/published/rejected/removed), the platform-wide star
 * distribution, the verified-purchase rate, the spread of feedback across subject
 * types, and the top-rated subjects. It composes aggregate reads only — it never
 * recomputes a rating, which remains the projector's job.
 */
final readonly class ReviewAnalyticsService
{
    /**
     * @param list<SubjectType> $subjectTypes
     */
    public function __construct(
        private ReviewStatsRepository $stats,
        private RatingSummaryRepository $summaries,
        private array $subjectTypes,
    ) {
    }

    /**
     * @return array{
     *     status_counts: array<string, int>,
     *     published: int,
     *     verified: int,
     *     verified_rate: float,
     *     distribution: array<int, int>,
     *     average: float,
     *     by_subject_type: array<string, int>
     * }
     */
    public function overview(): array
    {
        $statusCounts = $this->stats->countsByStatus();
        $published = $this->stats->publishedCount();
        $verified = $this->stats->verifiedCount();
        $distribution = $this->stats->publishedDistribution();

        $sum = 0;
        $total = 0;
        foreach ($distribution as $stars => $count) {
            $sum += $stars * $count;
            $total += $count;
        }

        return [
            'status_counts' => $statusCounts,
            'published' => $published,
            'verified' => $verified,
            'verified_rate' => $published > 0 ? round($verified / $published, 3) : 0.0,
            'distribution' => $distribution,
            'average' => $total > 0 ? round($sum / $total, 3) : 0.0,
            'by_subject_type' => $this->stats->countsBySubjectType($this->subjectTypes),
        ];
    }

    /**
     * The highest-rated subjects of a type with enough reviews to be meaningful.
     *
     * @return list<RatingSummary>
     */
    public function topRated(SubjectType $type, int $minCount, int $limit): array
    {
        return $this->summaries->topRated($type, $minCount, $limit);
    }
}
