<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Operations;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see ApprovalRequest} aggregate. */
interface ApprovalRequestRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?ApprovalRequest;

    /**
     * @return Paginated<ApprovalRequest>
     */
    public function search(?ApprovalStatus $status, ?string $subjectType, int $page, int $perPage): Paginated;

    public function save(ApprovalRequest $request): void;
}
