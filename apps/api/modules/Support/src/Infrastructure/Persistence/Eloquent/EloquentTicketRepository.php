<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Support\Domain\Enum\MessageAuthorType;
use EruoFood\Support\Domain\Enum\TicketChannel;
use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Enum\TicketStatus;
use EruoFood\Support\Domain\Ticket\Ticket;
use EruoFood\Support\Domain\Ticket\TicketMessage;
use EruoFood\Support\Domain\Ticket\TicketQuery;
use EruoFood\Support\Domain\Ticket\TicketRepository;
use EruoFood\Support\Domain\ValueObject\Attachment;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\Model\TicketModel;
use Illuminate\Support\Str;

final class EloquentTicketRepository implements TicketRepository
{
    public function __construct(private readonly string $refPrefix)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextMessageIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextReference(): string
    {
        $next = (int) TicketModel::query()->count() + 1;

        return sprintf('%s-%06d', $this->refPrefix, $next);
    }

    public function findById(string $id): ?Ticket
    {
        $m = TicketModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByRef(string $ref): ?Ticket
    {
        $m = TicketModel::query()->where('ref', $ref)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function search(TicketQuery $query): Paginated
    {
        $builder = TicketModel::query();
        if ($query->status !== null) {
            $builder->where('status', $query->status->value);
        }
        if ($query->priority !== null) {
            $builder->where('priority', $query->priority->value);
        }
        if ($query->assigneeId !== null) {
            $builder->where('assignee_id', $query->assigneeId);
        }
        if ($query->requesterId !== null) {
            $builder->where('requester_id', $query->requesterId);
        }
        if ($query->category !== null) {
            $builder->where('category', $query->category);
        }
        if ($query->unassignedOnly) {
            $builder->whereNull('assignee_id');
        }
        if ($query->openOnly) {
            $builder->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value]);
        }

        $paginator = $builder->orderByDesc('priority_weight')->orderByDesc('updated_at')
            ->paginate(perPage: $query->perPage, page: $query->page);

        return new Paginated(
            array_map(fn (TicketModel $m): Ticket => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $query->page,
            $query->perPage,
        );
    }

    public function breachingResolution(DateTimeImmutable $now, int $limit): array
    {
        return array_map(
            fn (TicketModel $m): Ticket => $this->toDomain($m),
            TicketModel::query()
                ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
                ->whereNull('merged_into_id')
                ->whereNull('resolved_at')
                ->whereNotNull('resolution_due_at')
                ->where('resolution_due_at', '<', $now)
                ->orderBy('resolution_due_at')
                ->limit($limit)
                ->get()
                ->all(),
        );
    }

    public function save(Ticket $ticket): void
    {
        $model = TicketModel::query()->find($ticket->id()) ?? new TicketModel();
        $model->id = $ticket->id();
        $model->ref = $ticket->ref();
        $model->requester_id = $ticket->requesterId();
        $model->subject = $ticket->subject();
        $model->category = $ticket->category();
        $model->channel = $ticket->channel()->value;
        $model->status = $ticket->status()->value;
        $model->priority = $ticket->priority()->value;
        $model->priority_weight = $ticket->priority()->weight();
        $model->assignee_id = $ticket->assigneeId();
        $model->sla_policy_id = $ticket->slaPolicyId();
        $model->first_response_due_at = $ticket->firstResponseDueAt();
        $model->resolution_due_at = $ticket->resolutionDueAt();
        $model->first_responded_at = $ticket->firstRespondedAt();
        $model->resolved_at = $ticket->resolvedAt();
        $model->closed_at = $ticket->closedAt();
        $model->tags = $ticket->tags();
        $model->related_order_id = $ticket->relatedOrderId();
        $model->merged_into_id = $ticket->mergedIntoId();
        $model->csat_score = $ticket->csatScore();
        $model->messages = array_map(fn (TicketMessage $msg): array => $this->messageToArray($msg), $ticket->messages());
        $model->created_at = $ticket->createdAt();
        $model->updated_at = $ticket->updatedAt();
        $model->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function messageToArray(TicketMessage $m): array
    {
        return [
            'id' => $m->id,
            'author_type' => $m->authorType->value,
            'author_id' => $m->authorId,
            'body' => $m->body,
            'internal' => $m->internal,
            'attachments' => array_map(static fn (Attachment $a): array => $a->toArray(), $m->attachments),
            'created_at' => $m->createdAt->format(DATE_ATOM),
        ];
    }

    private function toDomain(TicketModel $m): Ticket
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $m->messages ?? [];
        $messages = array_map(
            static function (array $r): TicketMessage {
                /** @var list<array<string, mixed>> $atts */
                $atts = is_array($r['attachments'] ?? null) ? $r['attachments'] : [];

                return new TicketMessage(
                    (string) $r['id'],
                    MessageAuthorType::from((string) $r['author_type']),
                    isset($r['author_id']) ? (string) $r['author_id'] : null,
                    (string) $r['body'],
                    (bool) $r['internal'],
                    array_map(static fn (array $a): Attachment => Attachment::fromArray($a), $atts),
                    new DateTimeImmutable((string) $r['created_at']),
                );
            },
            $rows,
        );

        return Ticket::reconstitute(
            id: $m->id,
            ref: $m->ref,
            requesterId: $m->requester_id,
            subject: $m->subject,
            category: $m->category,
            channel: TicketChannel::from($m->channel),
            status: TicketStatus::from($m->status),
            priority: TicketPriority::from($m->priority),
            assigneeId: $m->assignee_id,
            slaPolicyId: $m->sla_policy_id,
            firstResponseDueAt: $this->dt($m->first_response_due_at),
            resolutionDueAt: $this->dt($m->resolution_due_at),
            firstRespondedAt: $this->dt($m->first_responded_at),
            resolvedAt: $this->dt($m->resolved_at),
            closedAt: $this->dt($m->closed_at),
            tags: array_values(array_map('strval', $m->tags ?? [])),
            relatedOrderId: $m->related_order_id,
            mergedIntoId: $m->merged_into_id,
            csatScore: $m->csat_score !== null ? (int) $m->csat_score : null,
            messages: $messages,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }

    private function dt(mixed $value): ?DateTimeImmutable
    {
        return $value !== null ? DateTimeImmutable::createFromInterface($value) : null;
    }
}
