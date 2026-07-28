<?php

declare(strict_types=1);

use EruoFood\Support\Domain\Event\SlaBreached;
use EruoFood\Support\Infrastructure\Seeder\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('escalates a ticket and publishes a breach event when the resolution SLA passes', function (): void {
    $this->seed(SupportSeeder::class);
    ['token' => $customer] = supportUser($this, 'sla@example.com');

    $id = $this->withToken($customer)->postJson('/api/v1/support/tickets', [
        'subject' => 'Urgent issue', 'category' => 'billing', 'body' => 'Money missing', 'priority' => 'high',
    ])->assertCreated()->json('data.id');

    // Force the resolution deadline into the past.
    DB::table('support_tickets')->where('id', $id)->update([
        'resolution_due_at' => now()->subHour(),
    ]);

    $breaches = [];
    app('events')->listen('support.sla_breached', function (SlaBreached $e) use (&$breaches): void {
        $breaches[] = $e;
    });

    $this->artisan('support:sla-scan')->assertSuccessful();

    // The breach fired and the ticket escalated one priority (high → urgent).
    expect($breaches)->toHaveCount(1);
    $this->withToken($customer)->getJson("/api/v1/support/tickets/{$id}")
        ->assertOk()->assertJsonPath('data.priority', 'urgent');
});
