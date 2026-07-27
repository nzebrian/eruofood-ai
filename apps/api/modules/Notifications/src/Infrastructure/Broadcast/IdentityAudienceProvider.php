<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Broadcast;

use EruoFood\Notifications\Domain\Broadcast\AudienceProvider;
use Illuminate\Database\ConnectionInterface;

/**
 * Resolves a broadcast segment to recipient user ids. Segments:
 *   - "users:id1,id2,…" — an explicit list;
 *   - "all" / "active"   — all users (read-only lookup of the identity users
 *                          table by id — a soft reference, no join).
 * Kept behind the {@see AudienceProvider} port so the domain never depends on it.
 */
final readonly class IdentityAudienceProvider implements AudienceProvider
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function resolve(string $segment): array
    {
        if (str_starts_with($segment, 'users:')) {
            $ids = array_filter(array_map('trim', explode(',', substr($segment, 6))));

            return array_values($ids);
        }

        if (! $this->db->getSchemaBuilder()->hasTable('identity_users')) {
            return [];
        }

        $query = $this->db->table('identity_users');
        if ($segment === 'active' && $this->db->getSchemaBuilder()->hasColumn('identity_users', 'status')) {
            $query->where('status', 'active');
        }

        /** @var list<string> $ids */
        $ids = $query->pluck('id')->map(static fn ($v): string => (string) $v)->all();

        return $ids;
    }
}
