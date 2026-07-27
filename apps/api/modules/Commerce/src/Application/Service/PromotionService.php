<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\PromotionType;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Commerce\Domain\Promotion\Promotion;
use EruoFood\Commerce\Domain\Promotion\PromotionRepository;

/** Promotions & flash sales management (admin-curated). */
final readonly class PromotionService
{
    public function __construct(private PromotionRepository $promotions)
    {
    }

    /**
     * @param list<string> $productIds
     */
    public function create(
        ?string $storeId,
        string $name,
        PromotionType $type,
        int $value,
        array $productIds,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        bool $flashSale,
    ): Promotion {
        $promotion = Promotion::create(
            $this->promotions->nextIdentity(),
            $storeId,
            $name,
            $type,
            $value,
            $productIds,
            $startsAt,
            $endsAt,
            $flashSale,
        );
        $this->promotions->save($promotion);

        return $promotion;
    }

    /** @return list<Promotion> */
    public function active(): array
    {
        return $this->promotions->activeAt(new DateTimeImmutable());
    }

    /** @return list<Promotion> */
    public function flashSales(): array
    {
        return $this->promotions->activeFlashSales(new DateTimeImmutable());
    }

    public function delete(string $id): void
    {
        $this->promotions->findById($id) ?? throw CommerceNotFound::of('promotion', $id);
        $this->promotions->delete($id);
    }
}
