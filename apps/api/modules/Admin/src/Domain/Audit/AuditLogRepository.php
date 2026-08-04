<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Audit;

use EruoFood\Shared\Domain\Paginated;

/**
 * Append-only persistence port for the audit history. There is deliberately no
 * update or delete: entries are written once ({@see append()}) and only ever
 * read back ({@see query()}).
 */
interface AuditLogRepository
{
    public function nextIdentity(): string;

    public function append(AuditLogEntry $entry): void;

    /** @return Paginated<AuditLogEntry> */
    public function query(AuditQuery $query): Paginated;
}
