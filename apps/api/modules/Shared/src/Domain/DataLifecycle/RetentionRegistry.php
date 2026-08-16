<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\DataLifecycle;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Every kind of personal or sensitive data the platform holds, and what happens
 * to it.
 *
 * One place to answer "what do we keep, why, for how long, and who may see it" —
 * a question that currently has to be reassembled from `config/verification.php`,
 * `config/geo.php` and whatever the person answering remembers.
 *
 * The registry does not delete anything. It is the declaration; acting on it is
 * a separate, flagged, dry-runnable job, because deletion is the one operation
 * on this list that nobody can undo.
 */
final class RetentionRegistry
{
    /** @var array<string, RetentionPolicy> */
    private array $policies = [];

    public function register(RetentionPolicy $policy): void
    {
        if (isset($this->policies[$policy->key])) {
            throw new InvalidArgumentException("Retention policy '{$policy->key}' is already registered.");
        }

        $this->policies[$policy->key] = $policy;
    }

    public function get(string $key): RetentionPolicy
    {
        return $this->policies[$key]
            ?? throw new InvalidArgumentException("Unknown retention policy '{$key}'.");
    }

    /** @return list<RetentionPolicy> */
    public function all(): array
    {
        $policies = array_values($this->policies);
        usort($policies, static fn (RetentionPolicy $a, RetentionPolicy $b): int => $a->key <=> $b->key);

        return $policies;
    }

    /** @return list<RetentionPolicy> */
    public function forCategory(DataCategory $category): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (RetentionPolicy $p): bool => $p->category === $category,
        ));
    }

    /**
     * The platform's declared policies.
     *
     * Periods that already exist in configuration are referenced rather than
     * restated, so this cannot drift away from what the code actually does.
     */
    public static function platformDefaults(): self
    {
        $registry = new self();

        $registry->register(RetentionPolicy::of(
            key: 'verification.identity_documents',
            category: DataCategory::RegulatedIdentity,
            purpose: 'Prove a rider or merchant is who they claim, and evidence that check to a regulator.',
            retainDays: (int) config('verification.privacy.metadata_retention_days', 1825),
            deletionMode: DeletionMode::Destroy,
            accessPolicy: 'verification.pii permission only; every read audited (M24).',
            auditRequired: true,
        ));

        $registry->register(RetentionPolicy::of(
            key: 'geo.rider_locations',
            category: DataCategory::LocationTrail,
            purpose: 'Dispatch decisions and live delivery tracking. Worthless once the delivery ends, and a movement history thereafter.',
            retainDays: 30,
            deletionMode: DeletionMode::Destroy,
            accessPolicy: 'Never exposed through ordinary dispatch events, notifications or client responses (M26).',
        ));

        $registry->register(RetentionPolicy::of(
            key: 'payments.ledger',
            category: DataCategory::FinancialRecord,
            purpose: 'Statutory accounting record and the source of truth for every balance.',
            retainDays: 2555,
            deletionMode: DeletionMode::Archive,
            accessPolicy: 'Finance roles; append-only, never edited in place.',
            auditRequired: true,
        ));

        $registry->register(RetentionPolicy::of(
            key: 'admin.audit_entries',
            category: DataCategory::AuditTrail,
            purpose: 'Record who acted, on what authority. Its value is precisely that it names people.',
            retainDays: 2555,
            deletionMode: DeletionMode::Archive,
            accessPolicy: 'Admin audit roles; append-only, enforced by database trigger.',
        ));

        $registry->register(RetentionPolicy::of(
            key: 'notifications.sent',
            category: DataCategory::CommunicationLog,
            purpose: 'Show somebody what we sent them, and diagnose delivery failures.',
            retainDays: 365,
            deletionMode: DeletionMode::Anonymise,
            accessPolicy: 'The recipient, and support staff handling their case.',
        ));

        $registry->register(RetentionPolicy::of(
            key: 'shared.idempotency_keys',
            category: DataCategory::TransientTechnical,
            purpose: 'Collapse a retried money-moving request onto its original result.',
            retainDays: 1,
            deletionMode: DeletionMode::Destroy,
            accessPolicy: 'Internal; reconciliation exposes only the caller\'s own keys.',
        ));

        return $registry;
    }
}
