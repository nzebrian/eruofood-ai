<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Support\Ticket;
use EruoFood\Admin\Domain\Support\TicketMessage;
use EruoFood\Admin\Domain\Support\TicketPriority;
use EruoFood\Admin\Domain\Support\TicketRepository;
use EruoFood\Admin\Domain\Support\TicketStatus;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\TicketModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentTicketRepository implements TicketRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextMessageIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Ticket
    {
        $m = TicketModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function search(?TicketStatus $status, ?string $assigneeId, int $page, int $perPage): Paginated
    {
        $builder = TicketModel::query();
        if ($status !== null) {
            $builder->where('status', $status->value);
        }
        if ($assigneeId !== null) {
            $builder->where('assignee_id', $assigneeId);
        }
        // Live queue: most urgent first, then oldest waiting.
        $paginator = $builder->orderByDesc('priority_weight')->orderBy('updated_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (TicketModel $m): Ticket => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Ticket $ticket): void
    {
        $model = TicketModel::query()->find($ticket->id()) ?? new TicketModel();
        $model->id = $ticket->id();
        $model->requester_id = $ticket->requesterId();
        $model->subject = $ticket->subject();
        $model->category = $ticket->category();
        $model->status = $ticket->status()->value;
        $model->priority = $ticket->priority()->value;
        $model->priority_weight = $ticket->priority()->weight();
        $model->assignee_id = $ticket->assigneeId();
        $model->messages = array_map(static fn (TicketMessage $msg): array => [
            'id' => $msg->id,
            'author_id' => $msg->authorId,
            'body' => $msg->body,
            'internal' => $msg->internal,
            'created_at' => $msg->createdAt->format(DATE_ATOM),
        ], $ticket->messages());
        $model->created_at = $ticket->createdAt();
        $model->updated_at = $ticket->updatedAt();
        $model->save();
    }

    private function toDomain(TicketModel $m): Ticket
    {
        /** @var list<array{id: string, author_id: string, body: string, internal: bool, created_at: string}> $rows */
        $rows = $m->messages ?? [];
        $messages = array_map(
            static fn (array $r): TicketMessage => new TicketMessage(
                (string) $r['id'],
                (string) $r['author_id'],
                (string) $r['body'],
                (bool) $r['internal'],
                new DateTimeImmutable((string) $r['created_at']),
            ),
            $rows,
        );

        return Ticket::reconstitute(
            id: $m->id,
            requesterId: $m->requester_id,
            subject: $m->subject,
            category: $m->category,
            status: TicketStatus::from($m->status),
            priority: TicketPriority::from($m->priority),
            assigneeId: $m->assignee_id,
            messages: $messages,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
