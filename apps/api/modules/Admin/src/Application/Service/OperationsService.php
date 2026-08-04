<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use DateTimeImmutable;
use EruoFood\Admin\Application\DTO\VendorSummary;
use EruoFood\Admin\Application\Port\VendorDirectory;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Admin\Domain\Event\VendorApprovalDecided;
use EruoFood\Admin\Domain\Exception\AdminNotFound;
use EruoFood\Admin\Domain\Operations\ApprovalKind;
use EruoFood\Admin\Domain\Operations\ApprovalRequest;
use EruoFood\Admin\Domain\Operations\ApprovalRequestRepository;
use EruoFood\Admin\Domain\Operations\ApprovalStatus;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;

/**
 * Restaurant & Vendor Operations: onboarding approvals, business verification
 * and compliance reviews. A decision is recorded locally and published as
 * {@see VendorApprovalDecided}; the owning context (Marketplace/Commerce)
 * listens and flips the vendor's own status. Admin never writes their tables.
 */
final readonly class OperationsService
{
    public function __construct(
        private ApprovalRequestRepository $requests,
        private VendorDirectory $vendors,
        private AuditService $audit,
        private EventBus $events,
    ) {
    }

    /**
     * @param array<string, scalar|null> $details
     */
    public function submit(string $subjectType, string $subjectId, ApprovalKind $kind, array $details): ApprovalRequest
    {
        $request = ApprovalRequest::submit(
            $this->requests->nextIdentity(),
            $subjectType,
            $subjectId,
            $kind,
            $details,
            new DateTimeImmutable(),
        );
        $this->requests->save($request);

        return $request;
    }

    public function approve(string $actorId, string $id, ?string $note): ApprovalRequest
    {
        return $this->decide($actorId, $id, true, $note);
    }

    public function reject(string $actorId, string $id, ?string $note): ApprovalRequest
    {
        return $this->decide($actorId, $id, false, $note);
    }

    private function decide(string $actorId, string $id, bool $approved, ?string $note): ApprovalRequest
    {
        $request = $this->requests->findById($id) ?? throw AdminNotFound::of('approval request', $id);
        $now = new DateTimeImmutable();
        $approved ? $request->approve($actorId, $note, $now) : $request->reject($actorId, $note, $now);
        $this->requests->save($request);

        $this->audit->record(
            $actorId,
            AuditCategory::Operations,
            $approved ? 'ops.vendor_approved' : 'ops.vendor_rejected',
            $request->subjectType(),
            $request->subjectId(),
            ['note' => $note, 'kind' => $request->kind()->value],
        );
        $this->events->publish(new VendorApprovalDecided(
            $request->subjectId(),
            $request->subjectType(),
            $approved,
            $actorId,
            $note,
        ));

        return $request;
    }

    /**
     * @return Paginated<ApprovalRequest>
     */
    public function list(?ApprovalStatus $status, ?string $subjectType, int $page, int $perPage): Paginated
    {
        return $this->requests->search($status, $subjectType, $page, $perPage);
    }

    public function get(string $id): ApprovalRequest
    {
        return $this->requests->findById($id) ?? throw AdminNotFound::of('approval request', $id);
    }

    /**
     * @return Paginated<VendorSummary>
     */
    public function vendors(?string $query, ?string $status, int $page, int $perPage): Paginated
    {
        return $this->vendors->search($query, $status, $page, $perPage);
    }
}
