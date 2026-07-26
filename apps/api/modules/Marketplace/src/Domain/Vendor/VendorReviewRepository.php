<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Vendor;

use EruoFood\Shared\Domain\Paginated;

interface VendorReviewRepository
{
    public function nextIdentity(): string;

    public function findByVendorAndUser(string $vendorId, string $userId): ?VendorReview;

    /**
     * @return Paginated<VendorReview>
     */
    public function forVendor(string $vendorId, int $page, int $perPage): Paginated;

    public function save(VendorReview $review): void;

    /** @return array{average: float, count: int} */
    public function summaryForVendor(string $vendorId): array;
}
