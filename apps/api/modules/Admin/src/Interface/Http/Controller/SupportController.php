<?php

declare(strict_types=1);

namespace EruoFood\Admin\Interface\Http\Controller;

use EruoFood\Admin\Application\Service\AdminPresenter;
use EruoFood\Admin\Application\Service\PermissionService;
use EruoFood\Admin\Application\Service\SupportService;
use EruoFood\Admin\Domain\Rbac\Permission;
use EruoFood\Admin\Domain\Support\TicketPriority;
use EruoFood\Admin\Domain\Support\TicketStatus;
use EruoFood\Admin\Interface\Http\Concerns\AuthorizesAdmin;
use EruoFood\Admin\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Support Centre: the live ticket queue, replies, notes, assignment, escalation and resolution. */
final class SupportController
{
    use AuthorizesAdmin;
    use RespondsWithData;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly SupportService $support,
        private readonly AdminPresenter $presenter,
    ) {
    }

    protected function permissions(): PermissionService
    {
        return $this->permissions;
    }

    public function queue(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::SUPPORT_READ);
        $status = $request->query('status');
        $assignee = $request->query('assignee_id');

        return $this->paginated(
            $this->support->queue(
                is_string($status) ? TicketStatus::tryFrom($status) : null,
                is_string($assignee) ? $assignee : null,
                (int) $request->query('page', '1'),
                (int) $request->query('per_page', '20'),
            ),
            fn ($t): array => $this->presenter->ticket($t),
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::SUPPORT_READ);

        return $this->data($this->presenter->ticket($this->support->get($id)));
    }

    public function open(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::SUPPORT_MANAGE);
        $data = $request->validate([
            'requester_id' => ['required', 'uuid'],
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'priority' => ['nullable', 'in:'.implode(',', array_map(static fn (TicketPriority $p): string => $p->value, TicketPriority::cases()))],
            'body' => ['required', 'string'],
        ]);
        $ticket = $this->support->open(
            $data['requester_id'],
            $data['subject'],
            $data['category'],
            TicketPriority::from($data['priority'] ?? 'normal'),
            $data['body'],
        );

        return $this->data($this->presenter->ticket($ticket), 201);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::SUPPORT_MANAGE);
        $data = $request->validate(['agent_id' => ['required', 'uuid']]);

        return $this->data($this->presenter->ticket($this->support->assign($actor, $id, $data['agent_id'])));
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::SUPPORT_MANAGE);
        $data = $request->validate(['body' => ['required', 'string']]);

        return $this->data($this->presenter->ticket($this->support->reply($actor, $id, $data['body'])));
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::SUPPORT_MANAGE);
        $data = $request->validate(['body' => ['required', 'string']]);

        return $this->data($this->presenter->ticket($this->support->addNote($actor, $id, $data['body'])));
    }

    public function escalate(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::SUPPORT_MANAGE);
        $data = $request->validate([
            'priority' => ['required', 'in:'.implode(',', array_map(static fn (TicketPriority $p): string => $p->value, TicketPriority::cases()))],
        ]);

        return $this->data($this->presenter->ticket($this->support->escalate($actor, $id, TicketPriority::from($data['priority']))));
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::SUPPORT_MANAGE);

        return $this->data($this->presenter->ticket($this->support->resolve($actor, $id)));
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::SUPPORT_MANAGE);

        return $this->data($this->presenter->ticket($this->support->close($actor, $id)));
    }
}
