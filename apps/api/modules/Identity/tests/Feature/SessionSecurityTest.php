<?php

declare(strict_types=1);

use EruoFood\Identity\Application\DTO\SessionMetadata;
use EruoFood\Identity\Application\Port\RefreshTokenManager;
use EruoFood\Identity\Domain\ValueObject\UserId;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\RefreshTokenModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Session and device security.
 *
 * The property that matters here is stolen-token detection. Rotation on its own
 * already refuses an old secret — but it refuses *silently*, leaving an
 * attacker free to keep trying and the legitimate device signed in beside them.
 */

$manager = fn (): RefreshTokenManager => app(RefreshTokenManager::class);
$user = fn (): UserId => new UserId((string) Str::uuid());

// ------------------------------------------------------- reuse detection

it('revokes the whole session when a rotated-away token is replayed', function () use ($manager, $user): void {
    // The signature of a stolen token: an old copy presented after the real
    // device has already rotated past it.
    $id = $user();
    $issued = $manager()->issue($id, new SessionMetadata(deviceId: 'phone-1'));

    $rotated = $manager()->rotate($issued->plaintext, new SessionMetadata());
    expect($rotated)->not->toBeNull();

    // The thief presents the original.
    expect($manager()->rotate($issued->plaintext, new SessionMetadata()))->toBeNull();

    $row = RefreshTokenModel::query()->whereKey($issued->sessionId)->firstOrFail();

    expect($row->revoked_at)->not->toBeNull()
        ->and($row->reuse_detected_at)->not->toBeNull();

    // And the rotated token the real device holds is dead too — the session is
    // over for everybody, which is the point.
    expect($manager()->rotate($rotated->plaintext, new SessionMetadata()))->toBeNull();
});

it('does not flag an ordinary rotation as reuse', function () use ($manager, $user): void {
    // A false positive here signs a legitimate user out for no reason.
    $issued = $manager()->issue($user(), new SessionMetadata());

    $manager()->rotate($issued->plaintext, new SessionMetadata());

    expect(RefreshTokenModel::query()->whereKey($issued->sessionId)->firstOrFail()->reuse_detected_at)
        ->toBeNull();
});

it('does not overwrite an earlier revocation when a dead token is replayed', function () use ($manager, $user): void {
    // Replaying a token whose session already ended tells us nothing new, and
    // must not rewrite the record of when it actually ended.
    $id = $user();
    $issued = $manager()->issue($id, new SessionMetadata());

    $manager()->revokeSession($id, $issued->sessionId);
    $revokedAt = RefreshTokenModel::query()->whereKey($issued->sessionId)->firstOrFail()->revoked_at;

    $manager()->rotate($issued->plaintext, new SessionMetadata());

    expect(RefreshTokenModel::query()->whereKey($issued->sessionId)->firstOrFail()->revoked_at)
        ->toEqual($revokedAt);
});

it('ignores a malformed token rather than acting on it', function () use ($manager): void {
    expect($manager()->rotate('not-a-valid-token', new SessionMetadata()))->toBeNull()
        ->and(RefreshTokenModel::query()->count())->toBe(0);
});

it('does not revoke a session when an unrelated session id is guessed', function () use ($manager, $user): void {
    // A caller guessing session ids must not be able to sign other people out.
    $issued = $manager()->issue($user(), new SessionMetadata());

    $manager()->rotate((string) Str::uuid().'.wrong-secret', new SessionMetadata());

    expect(RefreshTokenModel::query()->whereKey($issued->sessionId)->firstOrFail()->revoked_at)->toBeNull();
});

// ------------------------------------------------------------ device identity

it('records device identity so a person can recognise a session', function () use ($manager, $user): void {
    $id = $user();
    $manager()->issue($id, new SessionMetadata(
        ipAddress: '10.0.0.1',
        userAgent: 'EruoFood/1.0',
        deviceId: 'device-abc',
        deviceName: "Ada's Pixel",
        platform: 'android',
    ));

    $sessions = $manager()->listSessions($id);

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->deviceName)->toBe("Ada's Pixel")
        ->and($sessions[0]->platform)->toBe('android')
        ->and($sessions[0]->lastActivityAt)->not->toBeNull();
});

it('never exposes the token hash through a session listing', function () use ($manager, $user): void {
    // A session list an attacker obtains must not help them impersonate the
    // device it describes.
    $id = $user();
    $manager()->issue($id, new SessionMetadata(deviceId: 'd1'));

    $stored = RefreshTokenModel::query()->where('user_id', $id->value())->firstOrFail();
    $encoded = json_encode($manager()->listSessions($id));

    expect($encoded)->not->toContain($stored->token_hash)
        ->and($encoded)->not->toContain('token_hash');
});

it('keeps the session id stable across rotation', function () use ($manager, $user): void {
    // Otherwise "revoke that device" would target a session that no longer
    // exists by the time the person taps it.
    $issued = $manager()->issue($user(), new SessionMetadata());
    $rotated = $manager()->rotate($issued->plaintext, new SessionMetadata());

    expect($rotated?->sessionId)->toBe($issued->sessionId);
});

it('revokes every session for a user on request', function () use ($manager, $user): void {
    $id = $user();
    $manager()->issue($id, new SessionMetadata(deviceId: 'a'));
    $manager()->issue($id, new SessionMetadata(deviceId: 'b'));

    $manager()->revokeAllForUser($id);

    expect($manager()->listSessions($id))->toBeEmpty();
});

it('does not let one user revoke another user\'s session', function () use ($manager, $user): void {
    $owner = $user();
    $intruder = $user();
    $issued = $manager()->issue($owner, new SessionMetadata());

    $manager()->revokeSession($intruder, $issued->sessionId);

    expect($manager()->listSessions($owner))->toHaveCount(1);
});
