<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Directory;

use DateTimeImmutable;
use EruoFood\Admin\Application\DTO\UserSummary;
use EruoFood\Admin\Application\Port\UserDirectory;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Database\Connection;

/**
 * Read-only adapter over the Identity context's users table. A soft reference —
 * a plain lookup by id/column, never a join — so Admin can display and search
 * users without owning their data. Moderation is effected via domain events,
 * not writes here.
 */
final readonly class IdentityUserDirectory implements UserDirectory
{
    private const TABLE = 'identity_users';

    public function __construct(private Connection $db)
    {
    }

    public function findById(string $userId): ?UserSummary
    {
        if (! $this->available()) {
            return null;
        }
        $row = $this->db->table(self::TABLE)->where('id', $userId)->first();

        return $row !== null ? $this->toSummary((array) $row) : null;
    }

    public function search(?string $query, ?string $status, int $page, int $perPage): Paginated
    {
        if (! $this->available()) {
            return new Paginated([], 0, $page, $perPage);
        }

        $builder = $this->db->table(self::TABLE);
        if ($query !== null && $query !== '') {
            $like = '%'.$query.'%';
            $builder->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
            });
        }
        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($r): UserSummary => $this->toSummary((array) $r))
            ->all();

        return new Paginated($rows, $total, $page, $perPage);
    }

    private function available(): bool
    {
        return $this->db->getSchemaBuilder()->hasTable(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSummary(array $row): UserSummary
    {
        $registered = $row['created_at'] ?? null;

        return new UserSummary(
            id: (string) $row['id'],
            name: (string) ($row['name'] ?? ''),
            email: (string) ($row['email'] ?? ''),
            status: (string) ($row['status'] ?? 'active'),
            verified: ($row['email_verified_at'] ?? null) !== null,
            registeredAt: is_string($registered) ? new DateTimeImmutable($registered) : null,
        );
    }
}
