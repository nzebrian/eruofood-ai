<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Auth;

use Carbon\CarbonImmutable;
use EruoFood\Identity\Application\DTO\IssuedRefreshToken;
use EruoFood\Identity\Application\DTO\SessionMetadata;
use EruoFood\Identity\Application\DTO\SessionView;
use EruoFood\Identity\Application\Port\RefreshTokenManager;
use EruoFood\Identity\Domain\ValueObject\UserId;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\RefreshTokenModel;
use Illuminate\Support\Str;

/**
 * Refresh tokens backed by the database. A row is a session/device. The token
 * plaintext is `sessionId.secret`; only a SHA-256 hash of the secret is stored,
 * so a database leak cannot reconstruct usable tokens. Rotation keeps the
 * sessionId stable while replacing the secret.
 */
final readonly class EloquentRefreshTokenManager implements RefreshTokenManager
{
    public function __construct(private int $ttlDays)
    {
    }

    public function issue(UserId $userId, SessionMetadata $meta): IssuedRefreshToken
    {
        $sessionId = (string) Str::orderedUuid();
        $secret = bin2hex(random_bytes(32));
        $expiresAt = CarbonImmutable::now()->addDays($this->ttlDays);

        RefreshTokenModel::query()->create([
            'id' => $sessionId,
            'user_id' => $userId->value(),
            'token_hash' => $this->hash($secret),
            'ip_address' => $meta->ipAddress,
            'user_agent' => $meta->userAgent,
            'expires_at' => $expiresAt,
            'last_used_at' => now(),
        ]);

        return new IssuedRefreshToken("{$sessionId}.{$secret}", $sessionId, $expiresAt->toDateTimeImmutable());
    }

    public function resolveUser(string $plaintext): ?UserId
    {
        $row = $this->findValid($plaintext);

        return $row !== null ? new UserId($row->user_id) : null;
    }

    public function rotate(string $plaintext, SessionMetadata $meta): ?IssuedRefreshToken
    {
        $row = $this->findValid($plaintext);
        if ($row === null) {
            return null;
        }

        $secret = bin2hex(random_bytes(32));
        $expiresAt = CarbonImmutable::now()->addDays($this->ttlDays);

        $row->token_hash = $this->hash($secret);
        $row->last_used_at = now();
        $row->expires_at = $expiresAt;
        $row->ip_address = $meta->ipAddress ?? $row->ip_address;
        $row->user_agent = $meta->userAgent ?? $row->user_agent;
        $row->save();

        return new IssuedRefreshToken("{$row->id}.{$secret}", $row->id, $expiresAt->toDateTimeImmutable());
    }

    public function revoke(string $plaintext): void
    {
        [$sessionId] = $this->split($plaintext);
        if ($sessionId === null) {
            return;
        }

        RefreshTokenModel::query()->whereKey($sessionId)->update(['revoked_at' => now()]);
    }

    public function revokeSession(UserId $userId, string $sessionId): void
    {
        RefreshTokenModel::query()
            ->whereKey($sessionId)
            ->where('user_id', $userId->value())
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(UserId $userId): void
    {
        RefreshTokenModel::query()
            ->where('user_id', $userId->value())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function listSessions(UserId $userId): array
    {
        return RefreshTokenModel::query()
            ->where('user_id', $userId->value())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (RefreshTokenModel $m): SessionView => new SessionView(
                sessionId: $m->id,
                ipAddress: $m->ip_address,
                userAgent: $m->user_agent,
                createdAt: $m->created_at->toDateTimeImmutable(),
                lastUsedAt: $m->last_used_at?->toDateTimeImmutable(),
            ))
            ->all();
    }

    private function findValid(string $plaintext): ?RefreshTokenModel
    {
        [$sessionId, $secret] = $this->split($plaintext);
        if ($sessionId === null || $secret === null) {
            return null;
        }

        $row = RefreshTokenModel::query()
            ->whereKey($sessionId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null || ! hash_equals($row->token_hash, $this->hash($secret))) {
            return null;
        }

        return $row;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function split(string $plaintext): array
    {
        $parts = explode('.', $plaintext, 2);

        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    private function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
