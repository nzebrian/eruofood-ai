<?php

declare(strict_types=1);

use EruoFood\Support\Domain\Enum\TicketChannel;
use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Enum\TicketStatus;
use EruoFood\Support\Domain\Exception\SupportInvalidState;
use EruoFood\Support\Domain\Ticket\Ticket;

function newTicket(TicketPriority $priority = TicketPriority::Normal): Ticket
{
    return Ticket::open('t1', 'EF-000001', 'user-1', 'Cannot pay', 'billing', TicketChannel::Web, $priority, 'm1', 'It fails', [], null, new DateTimeImmutable());
}

it('opens as new with the customer first message', function (): void {
    $t = newTicket();
    expect($t->status())->toBe(TicketStatus::New)
        ->and($t->messages())->toHaveCount(1)
        ->and($t->ref())->toBe('EF-000001');
});

it('assigns, records first response and hides internal notes', function (): void {
    $t = newTicket();
    $t->assign('agent-1', new DateTimeImmutable());
    expect($t->status())->toBe(TicketStatus::Open);

    $t->agentReply('m2', 'agent-1', 'On it', [], new DateTimeImmutable());
    expect($t->firstRespondedAt())->not->toBeNull();

    $t->addInternalNote('m3', 'agent-1', 'suspect gateway', new DateTimeImmutable());
    expect($t->messages())->toHaveCount(3)->and($t->publicMessages())->toHaveCount(2);
});

it('reopens on a customer reply after resolution', function (): void {
    $t = newTicket();
    $t->changeStatus(TicketStatus::Resolved, new DateTimeImmutable());
    expect($t->resolvedAt())->not->toBeNull();

    $t->customerReply('m2', 'still broken', [], new DateTimeImmutable());
    expect($t->status())->toBe(TicketStatus::Open)->and($t->resolvedAt())->toBeNull();
});

it('escalates priority one level', function (): void {
    $t = newTicket(TicketPriority::Normal);
    expect($t->escalate(new DateTimeImmutable()))->toBe(TicketPriority::High);
});

it('blocks illegal transitions and CSAT on open tickets', function (): void {
    $t = newTicket();
    $t->changeStatus(TicketStatus::Closed, new DateTimeImmutable());
    expect(fn () => $t->changeStatus(TicketStatus::Pending, new DateTimeImmutable()))->toThrow(SupportInvalidState::class);

    $fresh = newTicket();
    expect(fn () => $fresh->recordCsat(5))->toThrow(SupportInvalidState::class);
});

it('merges into another ticket, closing the source', function (): void {
    $t = newTicket();
    $t->mergeInto('target-99', 'sys1', new DateTimeImmutable());
    expect($t->isMerged())->toBeTrue()
        ->and($t->status())->toBe(TicketStatus::Closed)
        ->and(fn () => $t->agentReply('m9', 'a', 'x', [], new DateTimeImmutable()))->toThrow(SupportInvalidState::class);
});
