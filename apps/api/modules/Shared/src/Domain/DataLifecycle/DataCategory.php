<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\DataLifecycle;

/**
 * What kind of data this is, which decides how long it may be kept and who may
 * see it.
 *
 * ## Why categories rather than per-table rules
 *
 * "How long do we keep this?" is not a property of a table, it is a property of
 * *why we collected it*. A rider's GPS trail and a customer's delivery address
 * live in different tables and have the same answer: keep it while it is
 * operationally useful, then stop. A KYC document and a marketing preference
 * are both "about a person" and have completely different answers, because one
 * is a regulatory obligation and the other is a courtesy.
 *
 * M24 already applies this reasoning to verification data
 * (`metadata_retention_days`, `PurgeVerificationDataCommand`). This generalises
 * it so the same question has one shape everywhere rather than being re-argued
 * per module.
 */
enum DataCategory: string
{
    /**
     * Identity documents and verification evidence.
     *
     * The longest retention on the platform, and not by choice: financial
     * regulation requires it. Also the most tightly restricted — M24's
     * sensitive-access auditing exists for exactly this category.
     */
    case RegulatedIdentity = 'regulated_identity';

    /**
     * Ledger entries, payments, settlements.
     *
     * Retained for statutory accounting periods. Never anonymised in place: a
     * ledger you have edited is not a ledger.
     */
    case FinancialRecord = 'financial_record';

    /**
     * Where somebody was, and when.
     *
     * The most sensitive routine data the platform holds, and the least
     * valuable after the fact. A rider's position matters for the length of a
     * delivery; a month later it is a movement history nobody needs and an
     * attacker would like. Short retention here is a security control, not
     * housekeeping.
     */
    case LocationTrail = 'location_trail';

    /** Orders, deliveries, dispatch decisions — the operational record. */
    case OperationalRecord = 'operational_record';

    /** Who did what, on what authority. Append-only; never anonymised. */
    case AuditTrail = 'audit_trail';

    /** Messages and notifications sent to people. */
    case CommunicationLog = 'communication_log';

    /** Preferences, consents, marketing opt-ins. */
    case PreferenceRecord = 'preference_record';

    /** Idempotency claims, caches, transient working state. */
    case TransientTechnical = 'transient_technical';

    /**
     * Whether records in this category may be anonymised rather than deleted.
     *
     * Anonymisation keeps the row and removes the person from it — right for
     * an order history that still has to add up, wrong for an audit trail whose
     * entire purpose is naming who acted.
     */
    public function supportsAnonymisation(): bool
    {
        return match ($this) {
            self::OperationalRecord, self::CommunicationLog, self::PreferenceRecord => true,
            self::RegulatedIdentity, self::FinancialRecord, self::AuditTrail,
            self::LocationTrail, self::TransientTechnical => false,
        };
    }

    /**
     * Whether a person may demand erasure of this category on request.
     *
     * `false` does not mean "we ignore them" — it means the obligation to keep
     * it outranks the request, and the honest answer to the person is that we
     * are legally required to hold it for a stated period.
     */
    public function honoursErasureRequest(): bool
    {
        return match ($this) {
            self::RegulatedIdentity, self::FinancialRecord, self::AuditTrail => false,
            self::LocationTrail, self::OperationalRecord, self::CommunicationLog,
            self::PreferenceRecord, self::TransientTechnical => true,
        };
    }

    /** Whether this may appear in logs, traces or analytics at all. */
    public function mayEnterTelemetry(): bool
    {
        // Only the technical category. Everything else is about a person, and
        // requirement 14 forbids it reaching ordinary logs — which is a rule
        // that has to be checkable, not merely written down.
        return $this === self::TransientTechnical;
    }
}
