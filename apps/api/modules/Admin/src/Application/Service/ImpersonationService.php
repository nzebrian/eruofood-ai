<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Admin\Domain\Event\ImpersonationStarted;
use EruoFood\Admin\Domain\Exception\AdminInvalidState;
use EruoFood\Admin\Domain\Exception\AdminNotFound;
use EruoFood\Admin\Domain\Rbac\Impersonation;
use EruoFood\Admin\Domain\Rbac\ImpersonationRepository;
use EruoFood\Shared\Domain\EventBus;

/**
 * Opens and closes admin "act as user" sessions. Both transitions are
 * audit-logged, and starting one publishes {@see ImpersonationStarted} so
 * Identity (or a session gateway) can mint a scoped token — Admin never issues
 * sessions itself.
 */
final readonly class ImpersonationService
{
    public function __construct(
        private ImpersonationRepository $impersonations,
        private AuditService $audit,
        private EventBus $events,
    ) {
    }

    public function start(string $adminUserId, string $targetUserId, string $reason): Impersonation
    {
        if ($this->impersonations->activeForAdmin($adminUserId) !== null) {
            throw new AdminInvalidState('You already have an active impersonation session; end it first.');
        }

        $impersonation = Impersonation::start(
            $this->impersonations->nextIdentity(),
            $adminUserId,
            $targetUserId,
            $reason,
            new DateTimeImmutable(),
        );
        $this->impersonations->save($impersonation);

        $this->audit->record($adminUserId, AuditCategory::Rbac, 'impersonation.started', 'user', $targetUserId, [
            'reason' => $reason,
            'impersonation_id' => $impersonation->id(),
        ]);
        $this->events->publish(new ImpersonationStarted(
            $impersonation->id(),
            $adminUserId,
            $targetUserId,
            $reason,
        ));

        return $impersonation;
    }

    public function end(string $adminUserId, string $id): Impersonation
    {
        $impersonation = $this->impersonations->findById($id) ?? throw AdminNotFound::of('impersonation', $id);
        $impersonation->end(new DateTimeImmutable());
        $this->impersonations->save($impersonation);

        $this->audit->record($adminUserId, AuditCategory::Rbac, 'impersonation.ended', 'user', $impersonation->targetUserId(), [
            'impersonation_id' => $impersonation->id(),
        ]);

        return $impersonation;
    }

    public function activeForAdmin(string $adminUserId): ?Impersonation
    {
        return $this->impersonations->activeForAdmin($adminUserId);
    }
}
