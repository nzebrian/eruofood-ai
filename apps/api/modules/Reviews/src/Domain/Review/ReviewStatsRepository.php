<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Review;

use EruoFood\Reviews\Domain\Enum\SubjectType;

/**
 * Aggregate read model for review analytics — counts and rates the moderation
 * pipeline produces, kept separate from the per-subject rating summary. Backed by
 * cheap aggregate queries so the analytics workspace never loads whole reviews.
 */
interface ReviewStatsRepository
{
    /**
     * Count of reviews in each moderation status.
     *
     * @return array<string, int> status value => count
     */
    public function countsByStatus(): array;

    /**
     * The 1–5 star distribution across all published reviews.
     *
     * @return array<int, int> star (1-5) => count
     */
    public function publishedDistribution(): array;

    /** Total published reviews. */
    public function publishedCount(): int;

    /** Published reviews whose author is a verified purchaser. */
    public function verifiedCount(): int;

    /**
     * Count of published reviews per subject type — which parts of the catalog
     * attract the most feedback.
     *
     * @param list<SubjectType> $types
     *
     * @return array<string, int> subject type value => count
     */
    public function countsBySubjectType(array $types): array;
}
