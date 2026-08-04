<?php

declare(strict_types=1);

namespace EruoFood\Admin\Interface\Http\Controller;

use EruoFood\Admin\Application\Service\AdminPresenter;
use EruoFood\Admin\Application\Service\OperationsService;
use EruoFood\Admin\Application\Service\PermissionService;
use EruoFood\Admin\Domain\Operations\ApprovalKind;
use EruoFood\Admin\Domain\Operations\ApprovalStatus;
use EruoFood\Admin\Domain\Rbac\Permission;
use EruoFood\Admin\Interface\Http\Concerns\AuthorizesAdmin;
use EruoFood\Admin\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Restaurant & Vendor Operations: approval queue, decisions and the vendor directory. */
final class OperationsController
{
    use AuthorizesAdmin;
    use RespondsWithData;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly OperationsService $operations,
        private readonly AdminPresenter $presenter,
    ) {
    }

    protected function permissions(): PermissionService
    {
        return $this->permissions;
    }

    public function listApprovals(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::OPS_READ);
        $status = $request->query('status');
        $subjectType = $request->query('subject_type');

        return $this->paginated(
            $this->operations->list(
                is_string($status) ? ApprovalStatus::tryFrom($status) : null,
                is_string($subjectType) ? $subjectType : null,
                (int) $request->query('page', '1'),
                (int) $request->query('per_page', '20'),
            ),
            fn ($r): array => $this->presenter->approval($r),
        );
    }

    public function showApproval(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::OPS_READ);

        return $this->data($this->presenter->approval($this->operations->get($id)));
    }

    public function submitApproval(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::OPS_READ);
        $data = $request->validate([
            'subject_type' => ['required', 'string', 'max:50'],
            'subject_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:'.implode(',', array_map(static fn (ApprovalKind $k): string => $k->value, ApprovalKind::cases()))],
            'details' => ['nullable', 'array'],
        ]);
        $request2 = $this->operations->submit(
            $data['subject_type'],
            $data['subject_id'],
            ApprovalKind::from($data['kind']),
            $data['details'] ?? [],
        );

        return $this->data($this->presenter->approval($request2), 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::OPS_APPROVE);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->data($this->presenter->approval($this->operations->approve($actor, $id, $data['note'] ?? null)));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::OPS_APPROVE);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->data($this->presenter->approval($this->operations->reject($actor, $id, $data['note'] ?? null)));
    }

    public function vendors(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::OPS_READ);
        $query = $request->query('q');
        $status = $request->query('status');

        return $this->paginated(
            $this->operations->vendors(
                is_string($query) ? $query : null,
                is_string($status) ? $status : null,
                (int) $request->query('page', '1'),
                (int) $request->query('per_page', '20'),
            ),
            fn ($v): array => $this->presenter->vendor($v),
        );
    }
}
