<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Cms;

use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;

/** Persistence port for the {@see CmsPage} aggregate. */
interface CmsPageRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?CmsPage;

    public function findByTypeAndSlug(ContentType $type, Slug $slug): ?CmsPage;

    /**
     * @return Paginated<CmsPage>
     */
    public function search(?ContentType $type, ?PublishStatus $status, int $page, int $perPage): Paginated;

    public function save(CmsPage $page): void;

    public function delete(string $id): void;
}
