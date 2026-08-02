<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Application;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Application} (API client). */
interface ApplicationRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Application;

    /**
     * @return Paginated<Application>
     */
    public function forDeveloper(string $developerId, int $page, int $perPage): Paginated;

    public function save(Application $application): void;
}
