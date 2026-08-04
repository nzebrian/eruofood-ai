<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Directory;

use EruoFood\Admin\Application\DTO\VendorSummary;
use EruoFood\Admin\Application\Port\VendorDirectory;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Database\Connection;

/**
 * Read-only adapter over the Marketplace vendors table. A soft reference so the
 * admin Operations screens can display and search vendors; approval decisions
 * are published as domain events that Marketplace consumes.
 */
final readonly class MarketplaceVendorDirectory implements VendorDirectory
{
    private const TABLE = 'marketplace_vendors';

    public function __construct(private Connection $db)
    {
    }

    public function findById(string $vendorId): ?VendorSummary
    {
        if (! $this->available()) {
            return null;
        }
        $row = $this->db->table(self::TABLE)->where('id', $vendorId)->first();

        return $row !== null ? $this->toSummary((array) $row) : null;
    }

    public function search(?string $query, ?string $status, int $page, int $perPage): Paginated
    {
        if (! $this->available()) {
            return new Paginated([], 0, $page, $perPage);
        }

        $builder = $this->db->table(self::TABLE);
        if ($query !== null && $query !== '') {
            $builder->where('name', 'like', '%'.$query.'%');
        }
        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($r): VendorSummary => $this->toSummary((array) $r))
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
    private function toSummary(array $row): VendorSummary
    {
        return new VendorSummary(
            id: (string) $row['id'],
            name: (string) ($row['name'] ?? ''),
            type: (string) ($row['type'] ?? ''),
            status: (string) ($row['status'] ?? 'pending'),
        );
    }
}
