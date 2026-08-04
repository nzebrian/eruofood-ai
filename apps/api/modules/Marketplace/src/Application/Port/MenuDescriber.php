<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Port;

/**
 * Generates an appetising menu-item description via AI. The infrastructure
 * adapter bridges to the AI module's published contract, so the marketplace
 * never depends on AI internals (integration through Contracts only).
 */
interface MenuDescriber
{
    /**
     * @param list<string> $tags
     */
    public function describe(string $vendorName, string $itemName, string $category, array $tags, ?string $userId): string;
}
