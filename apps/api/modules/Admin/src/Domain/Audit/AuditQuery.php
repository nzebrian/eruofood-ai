<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Audit;

use EruoFood\Admin\Domain\Enum\AuditCategory;

/**
 * A filter over the append-only audit history. All fields are optional; an
 * empty query returns the most recent entries across every category.
 */
final readonly class AuditQuery
{
    public function __construct(
        public ?AuditCategory $category = null,
        public ?string $actorId = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {
    }
}
