<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Seeder;

use DateTimeImmutable;
use EruoFood\Reviews\Application\Service\RatingProjector;
use EruoFood\Reviews\Domain\Enum\ReviewStatus;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\Review\Review;
use EruoFood\Reviews\Domain\Review\ReviewRepository;
use EruoFood\Reviews\Domain\ValueObject\Rating;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use Illuminate\Database\Seeder;

/**
 * Seeds a couple of published reviews for a demo vendor and projects its rating
 * summary, so the storefront rating widget and the moderation queue are usable
 * out of the box. Idempotent — skips when the demo subject already has reviews.
 */
final class ReviewsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var ReviewRepository $reviews */
        $reviews = app(ReviewRepository::class);
        /** @var RatingProjector $projector */
        $projector = app(RatingProjector::class);

        $subject = new Subject(SubjectType::Vendor, 'demo-vendor');
        if ($reviews->publishedForSubject($subject) !== []) {
            return;
        }

        $now = new DateTimeImmutable();
        $samples = [
            ['author' => '00000000-0000-0000-0000-0000000000a1', 'rating' => 5, 'title' => 'Excellent jollof', 'body' => 'Fresh, hot and delivered fast.', 'verified' => true],
            ['author' => '00000000-0000-0000-0000-0000000000a2', 'rating' => 4, 'title' => 'Very good', 'body' => 'Great taste, portion could be bigger.', 'verified' => true],
            ['author' => '00000000-0000-0000-0000-0000000000a3', 'rating' => 3, 'title' => 'Okay', 'body' => 'Average experience overall.', 'verified' => false],
        ];

        foreach ($samples as $sample) {
            $reviews->save(Review::create(
                $reviews->nextIdentity(),
                $subject,
                (string) $sample['author'],
                new Rating((int) $sample['rating']),
                (string) $sample['title'],
                (string) $sample['body'],
                [],
                (bool) $sample['verified'],
                ReviewStatus::Published,
                $now,
            ));
        }

        $projector->project($subject);
    }
}
