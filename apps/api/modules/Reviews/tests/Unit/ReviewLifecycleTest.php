<?php

declare(strict_types=1);

use EruoFood\Reviews\Domain\Enum\ReviewStatus;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\Exception\ReviewsInvalidState;
use EruoFood\Reviews\Domain\Review\Review;
use EruoFood\Reviews\Domain\ValueObject\Rating;
use EruoFood\Reviews\Domain\ValueObject\Subject;

function newReview(ReviewStatus $status = ReviewStatus::Pending): Review
{
    return Review::create(
        'r1',
        new Subject(SubjectType::Vendor, 'v1'),
        'user-1',
        new Rating(4),
        'Nice',
        'Good food',
        [],
        true,
        $status,
        new DateTimeImmutable(),
    );
}

it('rejects a rating outside 1..5', function (): void {
    expect(fn () => new Rating(0))->toThrow(ReviewsInvalidState::class);
    expect(fn () => new Rating(6))->toThrow(ReviewsInvalidState::class);
    expect((new Rating(3))->value)->toBe(3);
});

it('counts helpful votes independently', function (): void {
    $r = newReview();
    $r->voteHelpful(true);
    $r->voteHelpful(true);
    $r->voteHelpful(false);
    expect($r->helpfulYes())->toBe(2)->and($r->helpfulNo())->toBe(1);
});

it('publishes a pending review and records an owner response', function (): void {
    $r = newReview();
    $r->publish(new DateTimeImmutable());
    expect($r->status())->toBe(ReviewStatus::Published);

    $r->respond('owner-1', 'Thank you!', new DateTimeImmutable());
    expect($r->ownerResponse())->not->toBeNull()
        ->and($r->ownerResponse()->body)->toBe('Thank you!');
});

it('cannot publish a rejected review', function (): void {
    $r = newReview();
    $r->reject('mod-1', 'spam', new DateTimeImmutable());
    expect($r->status())->toBe(ReviewStatus::Rejected);
    expect(fn () => $r->publish(new DateTimeImmutable()))->toThrow(ReviewsInvalidState::class);
});

it('only removes a published review', function (): void {
    $pending = newReview();
    expect(fn () => $pending->remove('mod-1', 'x', new DateTimeImmutable()))->toThrow(ReviewsInvalidState::class);

    $published = newReview(ReviewStatus::Published);
    $published->remove('mod-1', 'off-topic', new DateTimeImmutable());
    expect($published->status())->toBe(ReviewStatus::Removed)
        ->and($published->moderatedBy())->toBe('mod-1');
});

it('cannot edit a removed review', function (): void {
    $r = newReview(ReviewStatus::Published);
    $r->remove('mod-1', 'x', new DateTimeImmutable());
    expect(fn () => $r->edit(new Rating(2), null, null, [], new DateTimeImmutable()))
        ->toThrow(ReviewsInvalidState::class);
});

it('only a published review counts toward and is visible in the rating', function (): void {
    expect(ReviewStatus::Published->countsToRating())->toBeTrue()
        ->and(ReviewStatus::Pending->countsToRating())->toBeFalse()
        ->and(ReviewStatus::Removed->countsToRating())->toBeFalse()
        ->and(ReviewStatus::Published->isVisible())->toBeTrue()
        ->and(ReviewStatus::Rejected->isVisible())->toBeFalse();
});
