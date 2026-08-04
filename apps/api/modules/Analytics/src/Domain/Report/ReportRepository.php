<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Report;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for generated {@see Report}s. */
interface ReportRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Report;

    /** @return Paginated<Report> */
    public function recent(int $page, int $perPage): Paginated;

    public function save(Report $report): void;
}
