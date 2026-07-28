<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Cms\Banner;
use EruoFood\Admin\Domain\Cms\CmsPage;
use EruoFood\Admin\Domain\Cms\ContentType;
use EruoFood\Admin\Domain\Cms\PublishStatus;
use EruoFood\Admin\Domain\Cms\SeoMetadata;
use EruoFood\Admin\Domain\Exception\AdminInvalidState;
use EruoFood\Admin\Domain\Operations\ApprovalKind;
use EruoFood\Admin\Domain\Operations\ApprovalRequest;
use EruoFood\Admin\Domain\Operations\ApprovalStatus;
use EruoFood\Shared\Domain\ValueObject\Slug;

it('decides an approval request exactly once', function (): void {
    $request = ApprovalRequest::submit('a-1', 'vendor', 'v-1', ApprovalKind::Onboarding, [], new DateTimeImmutable());
    expect($request->status())->toBe(ApprovalStatus::Pending);

    $request->approve('admin-1', 'looks good', new DateTimeImmutable());
    expect($request->isApproved())->toBeTrue()
        ->and($request->decidedBy())->toBe('admin-1');

    expect(fn () => $request->reject('admin-2', null, new DateTimeImmutable()))
        ->toThrow(AdminInvalidState::class);
});

it('walks a CMS page draft → published → archived and guards re-publish', function (): void {
    $page = CmsPage::draft('p-1', ContentType::Page, Slug::fromTitle('About Us'), 'About Us', 'body', null, SeoMetadata::empty(), 'author-1', new DateTimeImmutable());
    expect($page->status())->toBe(PublishStatus::Draft)
        ->and($page->slug()->value)->toBe('about-us');

    $page->publish(new DateTimeImmutable());
    expect($page->isPublished())->toBeTrue()
        ->and($page->publishedAt())->not->toBeNull();

    $page->archive(new DateTimeImmutable());
    expect(fn () => $page->publish(new DateTimeImmutable()))->toThrow(AdminInvalidState::class);
});

it('bounds banner visibility by its active window', function (): void {
    $banner = Banner::create(
        'b-1', 'Sale', 'https://img', null, 'home', 0,
        new DateTimeImmutable('2026-07-01T00:00:00Z'),
        new DateTimeImmutable('2026-07-31T00:00:00Z'),
        new DateTimeImmutable(),
    );
    expect($banner->isVisibleAt(new DateTimeImmutable('2026-07-15T00:00:00Z')))->toBeTrue()
        ->and($banner->isVisibleAt(new DateTimeImmutable('2026-08-15T00:00:00Z')))->toBeFalse();

    $banner->deactivate();
    expect($banner->isVisibleAt(new DateTimeImmutable('2026-07-15T00:00:00Z')))->toBeFalse();
});
