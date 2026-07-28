<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use DateTimeImmutable;
use EruoFood\Support\Domain\Automation\AutomationRule;
use EruoFood\Support\Domain\Crm\CustomerProfile;
use EruoFood\Support\Domain\Crm\Interaction;
use EruoFood\Support\Domain\Csat\CsatResponse;
use EruoFood\Support\Domain\Knowledge\Article;
use EruoFood\Support\Domain\Ticket\Ticket;
use EruoFood\Support\Domain\Ticket\TicketMessage;

/** Maps Support domain objects to API-shaped arrays. */
final readonly class SupportPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function ticket(Ticket $t, bool $includeInternal = true): array
    {
        $now = new DateTimeImmutable();
        $messages = $includeInternal ? $t->messages() : $t->publicMessages();

        return [
            'id' => $t->id(),
            'ref' => $t->ref(),
            'requester_id' => $t->requesterId(),
            'subject' => $t->subject(),
            'category' => $t->category(),
            'channel' => $t->channel()->value,
            'status' => $t->status()->value,
            'priority' => $t->priority()->value,
            'assignee_id' => $t->assigneeId(),
            'tags' => $t->tags(),
            'related_order_id' => $t->relatedOrderId(),
            'merged_into_id' => $t->mergedIntoId(),
            'csat_score' => $t->csatScore(),
            'sla' => $this->sla($t, $now),
            'messages' => array_map(fn (TicketMessage $m): array => $this->message($m), $messages),
            'created_at' => $t->createdAt()->format(DATE_ATOM),
            'updated_at' => $t->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ticketSummary(Ticket $t): array
    {
        return [
            'id' => $t->id(),
            'ref' => $t->ref(),
            'subject' => $t->subject(),
            'status' => $t->status()->value,
            'priority' => $t->priority()->value,
            'assignee_id' => $t->assigneeId(),
            'category' => $t->category(),
            'sla' => $this->sla($t, new DateTimeImmutable()),
            'updated_at' => $t->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function message(TicketMessage $m): array
    {
        return [
            'id' => $m->id,
            'author_type' => $m->authorType->value,
            'author_id' => $m->authorId,
            'body' => $m->body,
            'internal' => $m->internal,
            'attachments' => array_map(static fn ($a): array => $a->toArray(), $m->attachments),
            'created_at' => $m->createdAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sla(Ticket $t, DateTimeImmutable $now): array
    {
        $status = $t->slaStatus($now);

        return [
            'state' => $status->label(),
            'breached' => $status->isBreached(),
            'first_response_due_at' => $status->firstResponseDueAt?->format(DATE_ATOM),
            'resolution_due_at' => $status->resolutionDueAt?->format(DATE_ATOM),
            'first_response_breached' => $status->firstResponseBreached,
            'resolution_breached' => $status->resolutionBreached,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function article(Article $a): array
    {
        return [
            'id' => $a->id(),
            'slug' => $a->slug()->value,
            'title' => $a->title(),
            'body' => $a->body(),
            'excerpt' => $a->excerpt(),
            'category' => $a->category(),
            'status' => $a->status()->value,
            'version' => $a->version(),
            'tags' => $a->tags(),
            'helpful_yes' => $a->helpfulYes(),
            'helpful_no' => $a->helpfulNo(),
            'published_at' => $a->publishedAt()?->format(DATE_ATOM),
            'updated_at' => $a->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(CustomerProfile $p): array
    {
        return [
            'user_id' => $p->userId(),
            'display_name' => $p->displayName(),
            'email' => $p->email(),
            'segment' => $p->segment()->value,
            'order_count' => $p->orderCount(),
            'total_spent_minor' => $p->totalSpentMinor(),
            'ticket_count' => $p->ticketCount(),
            'tags' => $p->tags(),
            'notes' => $p->notes(),
            'insight' => $p->insight(),
            'insight_generated_at' => $p->insightGeneratedAt()?->format(DATE_ATOM),
            'last_interaction_at' => $p->lastInteractionAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function interaction(Interaction $i): array
    {
        return [
            'id' => $i->id,
            'kind' => $i->kind,
            'summary' => $i->summary,
            'ref' => $i->ref,
            'source' => $i->source,
            'occurred_at' => $i->occurredAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rule(AutomationRule $r): array
    {
        return [
            'id' => $r->id(),
            'name' => $r->name(),
            'trigger' => $r->trigger(),
            'conditions' => $r->conditions(),
            'actions' => $r->actions(),
            'enabled' => $r->isEnabled(),
            'sort_order' => $r->sortOrder(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function csat(CsatResponse $c): array
    {
        return [
            'id' => $c->id,
            'ticket_id' => $c->ticketId,
            'score' => $c->score,
            'comment' => $c->comment,
            'created_at' => $c->createdAt->format(DATE_ATOM),
        ];
    }
}
