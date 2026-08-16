<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Reconciliation;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/**
 * The server's answer to "what happened to the thing I sent you?"
 *
 * ## The question a client cannot answer for itself
 *
 * A customer taps Pay, the connection dies, the app restarts. The app knows it
 * sent a request and knows it never heard back. Those two facts are compatible
 * with the payment having succeeded, having failed, and being in progress right
 * now. Every recovery strategy that guesses is wrong some of the time, and the
 * wrong guesses are "charged twice" and "told it failed when it did not".
 *
 * So the client does not guess: it asks, using the idempotency key it already
 * generated before sending. That key is the join. This is what comes back.
 *
 * ## Why the phase and not the raw status
 *
 * A client reconciling half a dozen operation types across six contexts should
 * not have to understand six vocabularies. It gets {@see ServerPhase} — enough
 * to decide between waiting, retrying and showing an outcome — plus the
 * context's own status for anything it wants to display.
 *
 * ## Never fabricated
 *
 * An operation the server has no record of is reported as
 * {@see ReconciliationOutcome::NeverReceived}, not as failed. Those are
 * different: "we never got it" means the client may safely send it again;
 * "it failed" might mean a provider declined it. Collapsing them is how a
 * retry becomes a double charge.
 */
final readonly class ReconcilableOperation
{
    private function __construct(
        public string $idempotencyKey,
        public ReconciliationOutcome $outcome,
        public ?ServerPhase $phase,
        public ?string $contextStatus,
        public ?string $resourceType,
        public ?string $resourceId,
        public ?DateTimeImmutable $lastUpdatedAt,
    ) {
    }

    /** The server has a record, and here is where it got to. */
    public static function known(
        string $idempotencyKey,
        ServerPhase $phase,
        string $contextStatus,
        string $resourceType,
        string $resourceId,
        DateTimeImmutable $lastUpdatedAt,
    ): self {
        return new self(
            $idempotencyKey,
            $phase->isTerminal() ? ReconciliationOutcome::Settled : ReconciliationOutcome::InProgress,
            $phase,
            $contextStatus,
            $resourceType,
            $resourceId,
            $lastUpdatedAt,
        );
    }

    /**
     * No record of this key at all.
     *
     * The request never arrived, or arrived and was rolled back before it took
     * effect. Either way nothing happened, so a resend is safe — and saying so
     * explicitly is what stops a client inventing a failure it then reports to
     * a customer.
     */
    public static function neverReceived(string $idempotencyKey): self
    {
        return new self($idempotencyKey, ReconciliationOutcome::NeverReceived, null, null, null, null, null);
    }

    /**
     * Received and still being worked on, with no outcome yet.
     *
     * Distinct from `InProgress` with a phase: this is the case where a claim
     * exists but the work has not recorded a result, which is exactly what a
     * crash mid-operation leaves behind. The client must wait, not retry.
     */
    public static function inFlight(string $idempotencyKey, DateTimeImmutable $claimedAt): self
    {
        return new self(
            $idempotencyKey,
            ReconciliationOutcome::InProgress,
            ServerPhase::Processing,
            null,
            null,
            null,
            $claimedAt,
        );
    }

    /**
     * Whether the client may safely send the original request again.
     *
     * The only affirmative case is `NeverReceived`. Anything the server has a
     * record of must be waited on or accepted, never re-sent — and the
     * idempotency key would collapse a resend anyway, which is the belt to this
     * braces.
     */
    public function isSafeToResend(): bool
    {
        return $this->outcome === ReconciliationOutcome::NeverReceived;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'outcome' => $this->outcome->value,
            'phase' => $this->phase?->value,
            'status' => $this->contextStatus,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'last_updated_at' => $this->lastUpdatedAt?->format(DATE_ATOM),
            'safe_to_resend' => $this->isSafeToResend(),
        ];
    }
}
