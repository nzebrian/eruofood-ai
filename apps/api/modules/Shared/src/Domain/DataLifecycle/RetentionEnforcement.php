<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\DataLifecycle;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * How each declared retention policy is actually enforced — or why it is not.
 *
 * ## The gap this closes
 *
 * {@see RetentionRegistry} answers "what do we keep, and for how long". Until
 * M42 nothing answered "and what deletes it", and the honest answer for six of
 * the seven policies was *nothing*. A registry that states a 30-day window while
 * the rows stay forever is not a control; it is a claim.
 *
 * So every non-indefinite policy must appear here with one of two things: the
 * command that enforces it, or a written reason it deliberately has none. There
 * is no third option and no default — {@see for()} throws on an unknown key, so
 * a policy added later fails loudly rather than being silently unenforced.
 *
 * ## Why this is not a second registry
 *
 * It holds no windows, no modes and no categories; `RetentionRegistry` remains
 * the only place those live. This maps a key to an enforcement path, and the
 * coverage test walks `RetentionRegistry` — not this file — so the two cannot
 * drift apart without a failure.
 */
final readonly class RetentionEnforcement
{
    /**
     * Policy key => the artisan command that enforces it.
     *
     * Every one of these ships as a DISABLED scheduled task, additionally gated
     * on {@see RetentionGate}. Existing means reachable, not running.
     */
    private const array COMMANDS = [
        'shared.idempotency_keys' => 'shared:purge-idempotency-keys',
        'geo.rider_locations' => 'geo:purge-rider-locations',
        'search.query_log' => 'search:purge-query-log',
        'verification.identity_documents' => 'verification:purge',
    ];

    /**
     * Policy key => why it deliberately has no automated enforcement.
     *
     * A reason, not an excuse: each names the specific obstacle, so a later
     * reader can tell a decision from an oversight.
     */
    private const array EXEMPT = [
        'notifications.sent' =>
            'Mode is Anonymise, and no anonymisation mechanism exists anywhere in the codebase to reuse. '
            .'"The record is kept with the person removed from it" would require clearing user_id, subject, '
            .'body, data and timeline on notifications_notifications — and every one of those columns is NOT '
            .'NULL. Honouring the mode therefore needs either a schema migration to make user_id nullable, or '
            .'a sentinel-value convention that does not exist in this repository. M42 was scoped to exclude '
            .'schema changes, so this was reported rather than guessed at. Converting the policy to Destroy '
            .'would be the wrong fix: the purpose ("show somebody what we sent them") survives anonymisation '
            .'and does not survive deletion.',

        'payments.ledger' =>
            'Mode is Archive over 2555 days (7 years), and Archive is the one reversible mode. Nothing is due '
            .'for another seven years, and archiving financial records is a storage-tiering decision with a '
            .'destination this platform has not chosen yet. Deleting a ledger row is never correct.',

        'admin.audit_entries' =>
            'Mode is Archive over 2555 days (7 years), same reasoning as payments.ledger. An audit trail also '
            .'cannot be anonymised — naming who acted is the whole point — which RetentionPolicy enforces '
            .'structurally.',
    ];

    /**
     * The enforcement command for a policy, or null when it is exempt.
     *
     * @throws InvalidArgumentException when the key is neither enforced nor
     *                                  explicitly exempt — which is the point:
     *                                  a new policy cannot arrive unenforced and
     *                                  unnoticed.
     */
    public static function for(string $policyKey): ?string
    {
        if (array_key_exists($policyKey, self::COMMANDS)) {
            return self::COMMANDS[$policyKey];
        }

        if (array_key_exists($policyKey, self::EXEMPT)) {
            return null;
        }

        throw new InvalidArgumentException(
            "Retention policy '{$policyKey}' has neither an enforcement command nor a documented exemption. "
            .'Add one to RetentionEnforcement, or the declared window is a claim nothing keeps.',
        );
    }

    /** The documented reason a policy has no automated enforcement, if exempt. */
    public static function exemptionReason(string $policyKey): ?string
    {
        return self::EXEMPT[$policyKey] ?? null;
    }

    /** @return list<string> every policy key with an enforcement command */
    public static function enforcedKeys(): array
    {
        return array_keys(self::COMMANDS);
    }

    /** @return list<string> every policy key deliberately left unenforced */
    public static function exemptKeys(): array
    {
        return array_keys(self::EXEMPT);
    }
}
