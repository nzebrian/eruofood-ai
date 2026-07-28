<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Port;

use EruoFood\Admin\Application\DTO\VendorSummary;
use EruoFood\Shared\Domain\Paginated;

/**
 * Read port over the vendor/restaurant directory owned by Marketplace/Commerce.
 * Used by the admin Operations screens to display who an approval request is
 * about. Approval decisions are communicated back as domain events, never by
 * writing another context's tables.
 */
interface VendorDirectory
{
    public function findById(string $vendorId): ?VendorSummary;

    /**
     * @return Paginated<VendorSummary>
     */
    public function search(?string $query, ?string $status, int $page, int $perPage): Paginated;
}
