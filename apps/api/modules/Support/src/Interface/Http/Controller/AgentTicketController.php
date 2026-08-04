<?php

declare(strict_types=1);

namespace EruoFood\Support\Interface\Http\Controller;

use EruoFood\Support\Application\Service\AgentAssistService;
use EruoFood\Support\Application\Service\SupportPresenter;
use EruoFood\Support\Application\Service\SupportService;
use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Enum\TicketStatus;
use EruoFood\Support\Domain\Ticket\TicketQuery;
use EruoFood\Support\Domain\ValueObject\Attachment;
use EruoFood\Support\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Support\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The agent workspace: the queue, ticket handling, workflow and AI assist. */
final class AgentTicketController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly SupportService $support,
        private readonly AgentAssistService $assist,
        private readonly SupportPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireAgent($request);
        $status = $request->query('status');
        $priority = $request->query('priority');
        $assignee = $request->query('assignee_id');

        $query = new TicketQuery(
            status: is_string($status) ? TicketStatus::tryFrom($status) : null,
            priority: is_string($priority) ? TicketPriority::tryFrom($priority) : null,
            assigneeId: is_string($assignee) ? $assignee : null,
            unassignedOnly: $request->boolean('unassigned'),
            openOnly: $request->boolean('open'),
            page: (int) $request->query('page', '1'),
            perPage: (int) $request->query('per_page', '20'),
        );

        return $this->paginated($this->support->queue($query), fn ($t): array => $this->presenter->ticketSummary($t));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data($this->presenter->ticket($this->support->get($id)));
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate(['agent_id' => ['nullable', 'uuid']]);
        $agentId = $data['agent_id'] ?? $this->currentUserId($request);

        return $this->data($this->presenter->ticket($this->support->assign($id, $agentId)));
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $agentId = $this->requireAgent($request);
        $data = $request->validate(['body' => ['required', 'string'], 'attachments' => ['nullable', 'array']]);

        return $this->data($this->presenter->ticket($this->support->agentReply($id, $agentId, $data['body'], $this->attachments($request))));
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $agentId = $this->requireAgent($request);
        $data = $request->validate(['body' => ['required', 'string']]);

        return $this->data($this->presenter->ticket($this->support->internalNote($id, $agentId, $data['body'])));
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate(['status' => ['required', 'in:new,open,pending,on_hold,resolved,closed']]);

        return $this->data($this->presenter->ticket($this->support->changeStatus($id, TicketStatus::from($data['status']))));
    }

    public function priority(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate(['priority' => ['required', 'in:low,normal,high,urgent']]);

        return $this->data($this->presenter->ticket($this->support->changePriority($id, TicketPriority::from($data['priority']))));
    }

    public function escalate(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->data($this->presenter->ticket($this->support->escalate($id, $data['reason'] ?? 'manual')));
    }

    public function merge(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate(['target_ticket_id' => ['required', 'uuid']]);

        return $this->data($this->presenter->ticket($this->support->merge($id, $data['target_ticket_id'])));
    }

    public function tag(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate(['tag' => ['required', 'string', 'max:60']]);

        return $this->data($this->presenter->ticket($this->support->addTag($id, $data['tag'])));
    }

    public function summarise(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data(['summary' => $this->assist->summarise($id)]);
    }

    public function suggestReply(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data(['suggestion' => $this->assist->suggestReply($id)]);
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(Request $request): array
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = (array) $request->input('attachments', []);

        return array_map(static fn (array $a): Attachment => Attachment::fromArray($a), $raw);
    }
}
