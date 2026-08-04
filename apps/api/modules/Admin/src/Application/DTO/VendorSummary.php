<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\DTO;

/**
 * A read-only projection of a vendor/restaurant from the Marketplace/Commerce
 * contexts, for the admin Operations screens. Assembled by the
 * {@see \EruoFood\Admin\Application\Port\VendorDirectory} adapter.
 */
final readonly class VendorSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $status,
    ) {
    }
}
