<?php

declare(strict_types=1);

use EruoFood\Reviews\Domain\Enum\ReviewStatus;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\Rating\RatingSummary;
use EruoFood\Reviews\Domain\Review\Review;
use EruoFood\Reviews\Domain\ValueObject\Rating;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Reviews\Infrastructure\Moderation\WordlistContentModerator;

function published(Subject $s, int $stars, bool $verified): Review
{
    return Review::create(
        bin2hex(random_bytes(8)),
        $s,
        'u-'.bin2hex(random_bytes(4)),
        new Rating($stars),
        null,
        null,
        [],
        $verified,
        ReviewStatus::Published,
        new DateTimeImmutable(),
    );
}

it('projects count, average, distribution and verified count', function (): void {
    $subject = new Subject(SubjectType::Vendor, 'v1');
    $reviews = [
        published($subject, 5, true),
        published($subject, 5, true),
        published($subject, 4, false),
        published($subject, 2, true),
    ];

    $summary = RatingSummary::fromReviews($subject, $reviews, new DateTimeImmutable());

    expect($summary->count)->toBe(4)
        ->and($summary->average)->toBe(4.0)
        ->and($summary->distribution[5])->toBe(2)
        ->and($summary->distribution[4])->toBe(1)
        ->and($summary->distribution[2])->toBe(1)
        ->and($summary->distribution[3])->toBe(0)
        ->and($summary->verifiedCount)->toBe(3);
});

it('produces an empty summary for a subject with no reviews', function (): void {
    $subject = new Subject(SubjectType::Product, 'p1');
    $summary = RatingSummary::empty($subject, new DateTimeImmutable());

    expect($summary->count)->toBe(0)
        ->and($summary->average)->toBe(0.0)
        ->and(array_sum($summary->distribution))->toBe(0);
});

it('flags blocked terms and passes clean, non-substring text', function (): void {
    $moderator = new WordlistContentModerator(['scam', 'fraud']);

    expect($moderator->screen('this is a scam')['ok'])->toBeFalse()
        ->and($moderator->screen('SCAM in caps')['ok'])->toBeFalse()
        ->and($moderator->screen('the scampi was great')['ok'])->toBeTrue()
        ->and($moderator->screen('excellent service')['ok'])->toBeTrue();
});
