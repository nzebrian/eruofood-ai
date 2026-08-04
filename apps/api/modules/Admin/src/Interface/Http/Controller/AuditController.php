<?php

declare(strict_types=1);

namespace EruoFood\Admin\Interface\Http\Controller;

use EruoFood\Admin\Application\Service\AdminPresenter;
use EruoFood\Admin\Application\Service\AuditService;
use EruoFood\Admin\Application\Service\PermissionService;
use EruoFood\Admin\Domain\Audit\AuditQuery;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Admin\Domain\Rbac\Permission;
use EruoFood\Admin\Interface\Http\Concerns\AuthorizesAdmin;
use EruoFood\Admin\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Audit & Compliance: the append-only activity/security history, filterable. */
final class AuditController
{
    use AuthorizesAdmin;
    use RespondsWithData;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
        private readonly AdminPresenter $presenter,
    ) {
    }

    protected function permissions(): PermissionService
    {
        return $this->permissions;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::AUDIT_READ);
        $category = $request->query('category');
        $actorId = $request->query('actor_id');
        $subjectType = $request->query('subject_type');
        $subjectId = $request->query('subject_id');

        $query = new AuditQuery(
            is_string($category) ? AuditCategory::tryFrom($category) : null,
            is_string($actorId) ? $actorId : null,
            is_string($subjectType) ? $subjectType : null,
            is_string($subjectId) ? $subjectId : null,
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '25'),
        );

        return $this->paginated($this->audit->query($query), fn ($e): array => $this->presenter->auditEntry($e));
    }
}
