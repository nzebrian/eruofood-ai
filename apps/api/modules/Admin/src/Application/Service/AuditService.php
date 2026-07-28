<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Audit\AuditLogEntry;
use EruoFood\Admin\Domain\Audit\AuditLogRepository;
use EruoFood\Admin\Domain\Audit\AuditQuery;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Shared\Domain\Paginated;

/**
 * The write + read side of the append-only audit trail. Every mutating admin
 * service records here via {@see record()}; the compliance screens read back
 * via {@see query()}. Deliberately the single place audit entries are created,
 * so the trail stays consistent.
 */
final readonly class AuditService
{
    public function __construct(
        private AuditLogRepository $log,
    ) {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function record(
        ?string $actorId,
        AuditCategory $category,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
        ?string $ip = null,
    ): void {
        $this->log->append(AuditLogEntry::record(
            $this->log->nextIdentity(),
            $actorId,
            $category,
            $action,
            $subjectType,
            $subjectId,
            $context,
            $ip,
            new DateTimeImmutable(),
        ));
    }

    /**
     * @return Paginated<AuditLogEntry>
     */
    public function query(AuditQuery $query): Paginated
    {
        return $this->log->query($query);
    }
}
