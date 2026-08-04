<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Cms;

/** Persistence port for the {@see FaqItem} aggregate. */
interface FaqRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?FaqItem;

    /**
     * All FAQ items, optionally narrowed to a category, ordered by sort order.
     *
     * @return list<FaqItem>
     */
    public function all(?string $category = null): array;

    public function save(FaqItem $item): void;

    public function delete(string $id): void;
}
