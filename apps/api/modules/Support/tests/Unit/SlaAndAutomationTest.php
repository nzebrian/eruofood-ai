<?php

declare(strict_types=1);

use EruoFood\Support\Domain\Automation\AutomationRule;
use EruoFood\Support\Domain\Crm\CustomerSegment;
use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Sla\SlaPolicy;
use EruoFood\Support\Domain\Sla\SlaStatus;

it('computes SLA due times from the policy', function (): void {
    $policy = SlaPolicy::define('p', 'Urgent', TicketPriority::Urgent, 30, 240);
    $open = new DateTimeImmutable('2026-07-01T10:00:00Z');
    expect($policy->firstResponseDueAt($open)->format('H:i'))->toBe('10:30')
        ->and($policy->resolutionDueAt($open)->format('H:i'))->toBe('14:00');
});

it('detects first-response and resolution breaches', function (): void {
    $due = new DateTimeImmutable('2026-07-01T10:30:00Z');
    $resDue = new DateTimeImmutable('2026-07-01T14:00:00Z');

    $breach = SlaStatus::evaluate($due, $resDue, null, null, new DateTimeImmutable('2026-07-01T11:00:00Z'));
    expect($breach->firstResponseBreached)->toBeTrue()->and($breach->label())->toBe('first_response_breached');

    $met = SlaStatus::evaluate($due, $resDue, new DateTimeImmutable('2026-07-01T10:15:00Z'), new DateTimeImmutable('2026-07-01T13:00:00Z'), new DateTimeImmutable('2026-07-01T13:30:00Z'));
    expect($met->isBreached())->toBeFalse()->and($met->label())->toBe('met');

    $late = SlaStatus::evaluate($due, $resDue, new DateTimeImmutable('2026-07-01T10:15:00Z'), null, new DateTimeImmutable('2026-07-01T15:00:00Z'));
    expect($late->resolutionBreached)->toBeTrue();
});

it('matches automation rules by trigger and conditions', function (): void {
    $rule = AutomationRule::create('r', 'Flag urgent', 'ticket_opened',
        [['field' => 'priority', 'op' => 'eq', 'value' => 'urgent']],
        [['type' => 'add_tag', 'value' => 'urgent-review']], 0);

    expect($rule->matches('ticket_opened', ['priority' => 'urgent']))->toBeTrue()
        ->and($rule->matches('ticket_opened', ['priority' => 'normal']))->toBeFalse()
        ->and($rule->matches('ticket_replied', ['priority' => 'urgent']))->toBeFalse();

    $rule->disable();
    expect($rule->matches('ticket_opened', ['priority' => 'urgent']))->toBeFalse();
});

it('derives customer segment from order count', function (): void {
    $t = ['vip' => 20, 'loyal' => 5, 'active' => 1, 'new' => 0];
    expect(CustomerSegment::fromOrderCount(25, $t))->toBe(CustomerSegment::Vip)
        ->and(CustomerSegment::fromOrderCount(5, $t))->toBe(CustomerSegment::Loyal)
        ->and(CustomerSegment::fromOrderCount(2, $t))->toBe(CustomerSegment::Active)
        ->and(CustomerSegment::fromOrderCount(0, $t))->toBe(CustomerSegment::New);
});
