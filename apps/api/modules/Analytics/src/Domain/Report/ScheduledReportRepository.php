<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Report;

/** Persistence port for {@see ScheduledReport}. */
interface ScheduledReportRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?ScheduledReport;

    /** @return list<ScheduledReport> */
    public function all(): array;

    /** @return list<ScheduledReport> scheduled reports due to run on or before $now */
    public function due(\DateTimeImmutable $now): array;

    public function save(ScheduledReport $report): void;
}
