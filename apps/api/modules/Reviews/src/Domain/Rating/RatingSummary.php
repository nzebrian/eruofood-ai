<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Rating;

use DateTimeImmutable;
use EruoFood\Reviews\Domain\Review\Review;
use EruoFood\Reviews\Domain\ValueObject\Subject;

/**
 * The authoritative rating summary for a subject — the read model other contexts
 * consume (via the published summary event) instead of computing ratings
 * themselves. It is a pure projection of the subject's published reviews:
 * count, average, the 1–5 star distribution and the verified-purchase count.
 */
final readonly class RatingSummary
{
    /**
     * @param array<int, int> $distribution star (1-5) => count
     */
    public function __construct(
        public Subject $subject,
        public int $count,
        public float $average,
        public array $distribution,
        public int $verifiedCount,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Recompute the summary from a subject's published reviews.
     *
     * @param list<Review> $published
     */
    public static function fromReviews(Subject $subject, array $published, DateTimeImmutable $now): self
    {
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $sum = 0;
        $verified = 0;
        foreach ($published as $review) {
            $stars = $review->rating()->value;
            $distribution[$stars] = ($distribution[$stars] ?? 0) + 1;
            $sum += $stars;
            if ($review->isVerifiedPurchase()) {
                $verified++;
            }
        }
        $count = count($published);

        return new self(
            $subject,
            $count,
            $count > 0 ? round($sum / $count, 3) : 0.0,
            $distribution,
            $verified,
            $now,
        );
    }

    public static function empty(Subject $subject, DateTimeImmutable $now): self
    {
        return new self($subject, 0, 0.0, [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0], 0, $now);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject_type' => $this->subject->type->value,
            'subject_id' => $this->subject->id,
            'count' => $this->count,
            'average' => $this->average,
            'distribution' => $this->distribution,
            'verified_count' => $this->verifiedCount,
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
