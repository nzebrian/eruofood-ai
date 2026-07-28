<?php

declare(strict_types=1);

use EruoFood\Commerce\Domain\Event\OrderPlaced;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('builds the CRM profile and timeline from published order events — no direct call', function (): void {
    ['token' => $agent] = supportUser($this, 'crm-agent@example.com', agent: true);
    $customerId = (string) Str::orderedUuid();

    // A business module publishes an order event on the shared bus.
    app(EventBus::class)->publish(new OrderPlaced((string) Str::orderedUuid(), $customerId, 1500000));
    app(EventBus::class)->publish(new OrderPlaced((string) Str::orderedUuid(), $customerId, 500000));

    // Support reacted, folding both into the customer's CRM profile.
    $this->withToken($agent)->getJson("/api/v1/support/crm/customers/{$customerId}")
        ->assertOk()
        ->assertJsonPath('data.order_count', 2)
        ->assertJsonPath('data.total_spent_minor', 2000000)
        ->assertJsonPath('data.segment', 'active');

    // …and the unified timeline shows the interactions.
    $this->withToken($agent)->getJson("/api/v1/support/crm/customers/{$customerId}/timeline")
        ->assertOk()->assertJsonPath('meta.total', 2);
});

it('generates an offline AI customer insight', function (): void {
    ['token' => $agent] = supportUser($this, 'crm-agent2@example.com', agent: true);
    $customerId = (string) Str::orderedUuid();
    app(EventBus::class)->publish(new OrderPlaced((string) Str::orderedUuid(), $customerId, 300000));

    $this->withToken($agent)->postJson("/api/v1/support/crm/customers/{$customerId}/insight")
        ->assertOk()->assertJsonPath('data.insight', fn ($v): bool => is_string($v) && $v !== '');
});
