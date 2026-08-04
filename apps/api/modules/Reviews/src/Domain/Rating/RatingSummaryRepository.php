<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Rating;

use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\ValueObject\Subject;

/** Persistence port for the {@see RatingSummary} projection. */
interface RatingSummaryRepository
{
    public function findBySubject(Subject $subject): ?RatingSummary;

    public function save(RatingSummary $summary): void;

    /**
     * The highest-rated subjects of a type with at least `$minCount` reviews —
     * for "top rated" discovery and analytics.
     *
     * @return list<RatingSummary>
     */
    public function topRated(SubjectType $type, int $minCount, int $limit): array;
}
