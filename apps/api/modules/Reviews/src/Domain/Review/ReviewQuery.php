<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Review;

use EruoFood\Reviews\Domain\Enum\ReviewStatus;
use EruoFood\Reviews\Domain\ValueObject\Subject;

/** A filter over reviews. `sort` is one of newest|oldest|helpful|rating_desc|rating_asc. */
final readonly class ReviewQuery
{
    public function __construct(
        public ?Subject $subject = null,
        public ?string $authorId = null,
        public ?ReviewStatus $status = null,
        public bool $verifiedOnly = false,
        public ?int $minRating = null,
        public string $sort = 'newest',
        public int $page = 1,
        public int $perPage = 20,
    ) {
    }
}
