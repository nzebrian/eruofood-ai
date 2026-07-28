<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Source;

use EruoFood\Search\Application\Port\SourceDocumentProvider;
use Illuminate\Database\ConnectionInterface;

/**
 * Base for the read-only source adapters. Each reads one other context's table
 * (a soft reference — no join, no write) to hydrate search documents. If the
 * table is absent (e.g. that module not migrated in a given test) it simply
 * yields nothing, so Search degrades gracefully.
 */
abstract class AbstractSourceProvider implements SourceDocumentProvider
{
    public function __construct(protected readonly ConnectionInterface $db)
    {
    }

    abstract protected function table(): string;

    protected function available(): bool
    {
        return $this->db->getSchemaBuilder()->hasTable($this->table());
    }

    public function allIds(): array
    {
        if (! $this->available()) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->baseQuery()->pluck('id')->map(static fn ($v): string => (string) $v)->all();

        return $ids;
    }

    /**
     * The query selecting indexable rows (e.g. published only). Subclasses may
     * override to add their status constraint.
     */
    protected function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->db->table($this->table());
    }

    /**
     * @param mixed $value
     * @return array<int|string, mixed>
     */
    protected function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Extract a list of strings from a decoded JSON list that may hold strings
     * or objects (picking `$key` from each object).
     *
     * @param mixed $value
     * @return list<string>
     */
    protected function names(mixed $value, string $key = 'name'): array
    {
        $out = [];
        foreach ($this->decode($value) as $item) {
            if (is_string($item)) {
                $out[] = $item;
            } elseif (is_array($item) && isset($item[$key]) && is_scalar($item[$key])) {
                $out[] = (string) $item[$key];
            }
        }

        return array_values(array_filter($out, static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    protected function stringList(mixed $value): array
    {
        $out = [];
        foreach ($this->decode($value) as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return array_values(array_filter($out, static fn (string $v): bool => $v !== ''));
    }

    protected function firstImage(mixed $images): ?string
    {
        $list = $this->stringList($images);

        return $list[0] ?? null;
    }
}
