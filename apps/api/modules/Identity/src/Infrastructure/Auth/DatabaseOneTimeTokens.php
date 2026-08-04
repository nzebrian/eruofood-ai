<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Auth;

use EruoFood\Identity\Application\Port\OneTimeTokens;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\OneTimeTokenModel;
use Illuminate\Support\Str;

/**
 * Single-use tokens (email verification, password reset) stored hashed with a
 * TTL. Consuming verifies the hash in constant time and deletes the row.
 */
final readonly class DatabaseOneTimeTokens implements OneTimeTokens
{
    public function issue(string $purpose, string $subject, int $ttlMinutes): string
    {
        $token = Str::random(64);

        // Only one live token per (purpose, subject).
        OneTimeTokenModel::query()
            ->where('purpose', $purpose)
            ->where('subject', $subject)
            ->delete();

        OneTimeTokenModel::query()->create([
            'id' => (string) Str::orderedUuid(),
            'purpose' => $purpose,
            'subject' => $subject,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $token;
    }

    public function consume(string $purpose, string $subject, string $token): bool
    {
        $row = OneTimeTokenModel::query()
            ->where('purpose', $purpose)
            ->where('subject', $subject)
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null || ! hash_equals($row->token_hash, hash('sha256', $token))) {
            return false;
        }

        $row->delete();

        return true;
    }
}
