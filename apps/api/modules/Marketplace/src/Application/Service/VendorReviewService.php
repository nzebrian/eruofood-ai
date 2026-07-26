<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Domain\Vendor\VendorRepository;
use EruoFood\Marketplace\Domain\Vendor\VendorReview;
use EruoFood\Marketplace\Domain\Vendor\VendorReviewRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Paginated;

/** Vendor ratings & reviews; keeps the vendor's denormalised rating in sync. */
final readonly class VendorReviewService
{
    public function __construct(
        private VendorReviewRepository $reviews,
        private VendorService $vendors,
        private VendorRepository $vendorRepo,
        private Clock $clock,
    ) {
    }

    /** Add or update the caller's review (one per user per vendor). */
    public function submit(string $vendorId, string $userId, int $rating, ?string $comment): VendorReview
    {
        $vendor = $this->vendors->get($vendorId);

        $review = $this->reviews->findByVendorAndUser($vendorId, $userId);
        if ($review !== null) {
            $review->update($rating, $comment);
        } else {
            $review = VendorReview::create(
                $this->reviews->nextIdentity(),
                $vendorId,
                $userId,
                $rating,
                $comment,
                $this->clock->now(),
            );
        }
        $this->reviews->save($review);

        $summary = $this->reviews->summaryForVendor($vendorId);
        $vendor->applyRatingSummary($summary['average'], $summary['count']);
        $this->vendorRepo->save($vendor);

        return $review;
    }

    /**
     * @return Paginated<VendorReview>
     */
    public function list(string $vendorId, int $page, int $perPage): Paginated
    {
        return $this->reviews->forVendor($vendorId, max(1, $page), min(60, max(1, $perPage)));
    }
}
