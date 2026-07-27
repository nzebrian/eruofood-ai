<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepts a signed webhook once and dedupes repeats (idempotency)', function (): void {
    $body = json_encode([
        'event_id' => 'evt_abc123',
        'type' => 'payment.succeeded',
        'reference' => 'mock_PMT-XYZ',
        'status' => 'succeeded',
        'amount_minor' => 500000,
    ]);

    // First delivery is applied.
    $this->call('POST', '/api/v1/payments/webhooks/mock', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk()
        ->assertJson(['received' => true, 'applied' => true]);

    // Duplicate delivery of the same event id is ignored.
    $this->call('POST', '/api/v1/payments/webhooks/mock', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk()
        ->assertJson(['received' => true, 'applied' => false]);
});
