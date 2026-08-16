<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\DataLifecycle;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * COLLECT → USE → RETAIN → ARCHIVE/DELETE, written down for one kind of data.
 *
 * ## Why the purpose is mandatory
 *
 * A retention period with no stated purpose cannot be defended and cannot be
 * reviewed. "We keep rider positions for 30 days" invites the question "why?",
 * and if the answer has to be reconstructed years later by whoever is left, the
 * honest outcome is that nobody dares delete anything. So the purpose is
 * required at construction, in the same spirit as a feature flag's rollback
 * strategy.
 *
 * ## Deletion is not the only ending
 *
 * Some data must be *destroyed*; some may be *anonymised*, keeping the row and
 * removing the person; some must be kept intact for a statutory period and only
 * then destroyed. {@see DeletionMode} names which, and the category constrains
 * the choice — an audit trail cannot be anonymised, because naming who acted is
 * the whole point of it.
 */
final readonly class RetentionPolicy
{
    private function __construct(
        public string $key,
        public DataCategory $category,
        public string $purpose,
        public int $retainDays,
        public DeletionMode $deletionMode,
        public string $accessPolicy,
        public bool $auditRequired,
    ) {
    }

    public static function of(
        string $key,
        DataCategory $category,
        string $purpose,
        int $retainDays,
        DeletionMode $deletionMode,
        string $accessPolicy,
        bool $auditRequired = false,
    ): self {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A retention policy needs a key.');
        }

        if (trim($purpose) === '') {
            throw new InvalidArgumentException(
                "Retention policy '{$key}' needs a stated purpose — a period nobody can justify is one nobody will ever act on.",
            );
        }

        if (trim($accessPolicy) === '') {
            throw new InvalidArgumentException("Retention policy '{$key}' needs an access policy.");
        }

        if ($retainDays < 0) {
            throw new InvalidArgumentException("Retention policy '{$key}' cannot retain for a negative period.");
        }

        if ($deletionMode === DeletionMode::Anonymise && ! $category->supportsAnonymisation()) {
            // Structural, not advisory. Anonymising an audit trail or a ledger
            // destroys the property that makes it worth keeping.
            throw new InvalidArgumentException(
                "Retention policy '{$key}' cannot anonymise {$category->value} data — that category must be kept intact or destroyed.",
            );
        }

        return new self($key, $category, $purpose, $retainDays, $deletionMode, $accessPolicy, $auditRequired);
    }

    /** Whether records under this policy ever expire at all. */
    public function isIndefinite(): bool
    {
        return $this->retainDays === 0;
    }

    /** Whether acting on this policy must itself leave an audit record. */
    public function requiresAudit(): bool
    {
        // Deleting regulated or financial data is an event somebody will later
        // need to account for, whatever the policy says.
        return $this->auditRequired
            || $this->category === DataCategory::RegulatedIdentity
            || $this->category === DataCategory::FinancialRecord;
    }
}
