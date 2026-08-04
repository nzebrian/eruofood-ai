<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * @return array{token: string, id: string}
 */
function superAdmin(object $test, string $email): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Super', 'email' => $email,
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    app(AdminAccountRepository::class)->save(
        AdminAccount::grant($data['user']['id'], [AdminRole::SuperAdmin], new DateTimeImmutable()),
    );

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

it('creates, publishes a CMS page and writes an audit entry', function (): void {
    ['token' => $token] = superAdmin($this, 'cms@example.com');

    $id = $this->withToken($token)->postJson('/api/v1/admin/cms/pages', [
        'type' => 'page', 'title' => 'Privacy Policy', 'body' => 'The policy…',
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    $this->withToken($token)->postJson("/api/v1/admin/cms/pages/{$id}/publish")
        ->assertOk()->assertJsonPath('data.status', 'published');

    // The action landed in the audit trail.
    $this->withToken($token)->getJson('/api/v1/admin/audit?category=content')
        ->assertOk()->assertJsonPath('data.0.action', 'cms.page_published');
});

it('seeds settings and updates one', function (): void {
    ['token' => $token] = superAdmin($this, 'cfg@example.com');
    $this->seed(\EruoFood\Admin\Infrastructure\Seeder\AdminSeeder::class);

    $this->withToken($token)->getJson('/api/v1/admin/settings?group=app')
        ->assertOk()->assertJsonPath('data.settings.0.group', 'app');

    $this->withToken($token)->putJson('/api/v1/admin/settings/app.name', ['value' => 'EruoFood NG'])
        ->assertOk()->assertJsonPath('data.value', 'EruoFood NG');
});

it('suspends a user by publishing a domain event (no direct write)', function (): void {
    ['token' => $token] = superAdmin($this, 'mod@example.com');
    ['id' => $targetId] = superAdmin($this, 'target@example.com');

    $captured = [];
    app(EventBus::class); // ensure resolvable
    app('events')->listen('admin.user_suspended', function ($event) use (&$captured): void {
        $captured[] = $event;
    });

    $this->withToken($token)->postJson("/api/v1/admin/users/{$targetId}/suspend", [
        'reason' => 'Policy violation',
    ])->assertOk()->assertJsonPath('data.status', 'suspended');

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->userId)->toBe($targetId);
});

it('toggles maintenance mode', function (): void {
    ['token' => $token] = superAdmin($this, 'maint@example.com');

    $this->withToken($token)->putJson('/api/v1/admin/maintenance', [
        'enabled' => true, 'message' => 'Back soon',
    ])->assertOk()->assertJsonPath('data.enabled', true);

    $this->withToken($token)->getJson('/api/v1/admin/maintenance')
        ->assertOk()->assertJsonPath('data.message', 'Back soon');
});
