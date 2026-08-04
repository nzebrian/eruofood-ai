<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Pricing;

use DateTimeImmutable;
use EruoFood\Commerce\Application\Port\PricingStrategy;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Promotion\PromotionRepository;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * The default pricing strategy: catalogue price (base + variant delta), with the
 * best currently-active promotion applied. This is the seam for dynamic pricing —
 * a demand/inventory/time-aware strategy can replace it behind the same port
 * without touching the cart or checkout.
 */
final readonly class CataloguePricingStrategy implements PricingStrategy
{
    public function __construct(private PromotionRepository $promotions)
    {
    }

    public function priceFor(Product $product, ?string $variantSku): Money
    {
        $price = $product->priceFor($variantSku);
        $now = new DateTimeImmutable();

        $best = $price;
        foreach ($this->promotions->activeAt($now) as $promotion) {
            if (! $promotion->appliesTo($product->id())) {
                continue;
            }
            $candidate = $promotion->applyTo($price);
            if ($candidate->minorUnits < $best->minorUnits) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
