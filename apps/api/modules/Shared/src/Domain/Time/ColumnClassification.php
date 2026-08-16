<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Time;

/**
 * One column's verdict in the UTC cutover, with the reason attached.
 *
 * The reason is not decoration. This classification decides whether a one-way
 * rewrite touches historical financial, KYC and dispatch data, and "why was
 * `verification_cases.expires_at` left alone?" is a question somebody will ask
 * long after the person who decided it has moved on.
 */
final readonly class ColumnClassification
{
    public function __construct(
        public string $table,
        public string $column,
        public BackfillCategory $category,
        public string $reason,
    ) {
    }

    public function isConverted(): bool
    {
        return $this->category->isConverted();
    }

    public function qualifiedName(): string
    {
        return "{$this->table}.{$this->column}";
    }
}
