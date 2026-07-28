<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Cms;

/** Persistence port for the {@see Banner} aggregate. */
interface BannerRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Banner;

    /**
     * All banners, optionally narrowed to a placement, ordered by sort order.
     *
     * @return list<Banner>
     */
    public function all(?string $placement = null): array;

    public function save(Banner $banner): void;

    public function delete(string $id): void;
}
