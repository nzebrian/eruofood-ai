<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Exception\AdminInvalidState;
use EruoFood\Admin\Domain\Support\Ticket;
use EruoFood\Admin\Domain\Support\TicketPriority;
use EruoFood\Admin\Domain\Support\TicketStatus;

function ticket(): Ticket
{
    return Ticket::open('t-1', 'user-1', 'Cannot checkout', 'billing', TicketPriority::Normal, 'It fails', 'm-1', new DateTimeImmutable());
}

it('opens with the requester first message and hides internal notes from the public view', function (): void {
    $t = ticket();
    expect($t->status())->toBe(TicketStatus::Open)
        ->and($t->messages())->toHaveCount(1);

    $t->reply('m-2', 'agent-1', 'Looking into it', new DateTimeImmutable());
    $t->addInternalNote('m-3', 'agent-1', 'Suspect a config issue', new DateTimeImmutable());

    expect($t->messages())->toHaveCount(3)
        ->and($t->publicMessages())->toHaveCount(2);
});

it('assigns, escalates, resolves and reopens', function (): void {
    $t = ticket();
    $t->assign('agent-1', new DateTimeImmutable());
    expect($t->status())->toBe(TicketStatus::Pending)
        ->and($t->assigneeId())->toBe('agent-1');

    $t->escalate(TicketPriority::Urgent, new DateTimeImmutable());
    expect($t->priority())->toBe(TicketPriority::Urgent);

    $t->resolve(new DateTimeImmutable());
    expect($t->status())->toBe(TicketStatus::Resolved);

    $t->reopen(new DateTimeImmutable());
    expect($t->status())->toBe(TicketStatus::Open);
});

it('refuses to reply on a closed ticket', function (): void {
    $t = ticket();
    $t->close(new DateTimeImmutable());
    expect(fn () => $t->reply('m-9', 'agent-1', 'hi', new DateTimeImmutable()))
        ->toThrow(AdminInvalidState::class);
});
